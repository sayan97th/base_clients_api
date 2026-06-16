<?php

namespace App\Console\Commands\Import;

use App\Models\CreditTransaction;
use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderItem;
use App\Models\User;
use App\Services\LegacyInvoiceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportLegacyOrders extends Command
{
    # php artisan orders:import --update --skip-unknown-users
    protected $signature = 'orders:import
                            {file? : Path to the CSV orders export file (defaults to import/orders/base-orders.csv)}
                            {transactions-file? : Path to the CSV transactions export file used to reconcile order totals (defaults to import/transactions/base-transactions.csv)}
                            {--dry-run : Preview the import without saving any data}
                            {--force : Skip duplicate orders instead of failing}
                            {--update : Update existing orders and credit transactions instead of skipping them}
                            {--skip-unknown-users : Skip rows whose client email is not found in the system}';

    protected $description = 'Import legacy orders from a CSV export file into the new application';

    public function __construct(private readonly LegacyInvoiceService $legacy_invoice_service)
    {
        parent::__construct();
    }

    /** @var array<string, array<string, string>> Transaction rows keyed by their exact legacy id. */
    private array $transactions_by_id = [];

    /** @var array<string, array<int, array{id: string, refund: float}>> Refund entries keyed by the Stripe charge (transaction_id) they apply to. */
    private array $refund_rows_by_charge = [];

    private const STATUS_MAP = [
        'working'        => 'processing',
        'order complete' => 'completed',
        'completed'      => 'completed',
        'cancelled'      => 'cancelled',
        'canceled'       => 'cancelled',
        'pending'        => 'pending',
    ];

    private const FIELD_MAPPING = [
        'id'               => 'link_building_orders.session_id  (legacy reference)',
        'status'           => 'link_building_orders.status',
        'title'            => 'link_building_orders.order_title',
        'service'          => 'link_building_order_items.dr_tier_id  (via label lookup)',
        'price'            => 'link_building_order_items.unit_price',
        'quantity'         => 'link_building_order_items.quantity',
        'created_at'       => 'link_building_orders.created_at',
        'updated_at'       => 'link_building_orders.updated_at',
        'client_email'     => 'link_building_orders.user_id  (via email lookup)',
        'date_started'     => 'link_building_orders.order_notes',
        'date_due'         => 'link_building_orders.order_notes',
        'date_completed'   => 'link_building_orders.order_notes',
        'last_message_at'  => 'link_building_orders.order_notes',
        'invoice_paysys'   => 'link_building_orders.order_notes',
        'invoice_currency' => 'link_building_orders.order_notes',
        'employees'        => 'link_building_orders.order_notes',
    ];

    private const SKIPPED_FIELDS = [
        'client_name_f',
        'client_name_l',
        'service_id',
        'subscription_id',
        'subscription_status',
        'tags',
        'options',
    ];

    public function handle(): int
    {
        $file_path              = $this->argument('file') ?? base_path('import/orders/base-orders.csv');
        $transactions_file_path = $this->argument('transactions-file') ?? base_path('import/transactions/base-transactions.csv');
        $dry_run                = (bool) $this->option('dry-run');
        $force                  = (bool) $this->option('force');
        $update                 = (bool) $this->option('update');
        $skip_unknown_users     = (bool) $this->option('skip-unknown-users');

        if (!$this->argument('file')) {
            $this->line("No file specified. Using default: <fg=yellow>import/orders/base-orders.csv</>");
            $this->newLine();
        }

        if (!file_exists($file_path)) {
            $this->error("File not found: {$file_path}");
            return self::FAILURE;
        }

        $this->info('=== Legacy Order Import ===');
        $this->newLine();

        if ($dry_run) {
            $this->warn('[DRY RUN] No data will be saved to the database.');
            $this->newLine();
        }

        $this->loadTransactions($transactions_file_path);

        $rows = $this->parseCsv($file_path);
        if ($rows === null) {
            return self::FAILURE;
        }

        $groups = $this->groupRowsByBaseId($rows);
        $total  = count($groups);

        $this->line("Found <fg=yellow>" . count($rows) . "</> row(s) grouped into <fg=yellow>{$total}</> order(s).");
        $this->newLine();

        $this->printFieldMapping($rows[0] ?? []);

        $this->line('<fg=yellow>Note:</> Orders paid with "Account Balance" (CRD) are imported as link-building orders.');
        $this->line('<fg=yellow>Note:</> Credit-package purchases are imported as credit transactions.');
        $this->line('<fg=yellow>Note:</> Order totals are reconciled against <fg=white>' . basename($transactions_file_path) . '</> (' . count($this->transactions_by_id) . ' matched record(s)) to net out cancellations/refunds.');
        $this->newLine();

        if (!$this->confirm('Proceed with the import?', true)) {
            $this->info('Import cancelled.');
            return self::SUCCESS;
        }

        $this->newLine();

        $stats  = ['imported' => 0, 'credited' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($groups as $base_id => $group_rows) {
            try {
                $result          = $this->processGroup($base_id, $group_rows, $dry_run, $force, $update, $skip_unknown_users);
                $stats[$result]++;
            } catch (Throwable $e) {
                $first_email = trim($group_rows[0]['client_email'] ?? '');
                $stats['failed']++;
                $errors[] = "Order [{$base_id}] [{$first_email}]: {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->printSummary($stats, $errors, $dry_run);

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function groupRowsByBaseId(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $raw_id  = trim($row['id'] ?? '');
            // Strip trailing "_N" suffix so split rows (e.g. 5501A132_1, _2) share one group
            $base_id = preg_replace('/_\d+$/', '', $raw_id) ?: $raw_id;

            $groups[$base_id][] = $row;
        }

        return $groups;
    }

    private function processGroup(
        string $base_id,
        array $rows,
        bool $dry_run,
        bool $force,
        bool $update,
        bool $skip_unknown_users,
    ): string {
        $first_row    = $rows[0];
        $client_email = trim($first_row['client_email'] ?? '');

        if ($client_email === '') {
            throw new \RuntimeException('Missing client email');
        }

        $user = User::where('email', $client_email)->first();
        if (!$user) {
            if ($skip_unknown_users) {
                return 'skipped';
            }
            throw new \RuntimeException("User not found in the system: {$client_email}");
        }

        if ($this->isCreditPurchase($rows)) {
            return $this->processCreditGroup($base_id, $rows, $user, $dry_run, $force, $update);
        }

        return $this->processOrderGroup($base_id, $rows, $user, $dry_run, $force, $update);
    }

    private function processOrderGroup(
        string $base_id,
        array $rows,
        User $user,
        bool $dry_run,
        bool $force,
        bool $update,
    ): string {
        $existing = LinkBuildingOrder::where('session_id', $base_id)->first();

        if ($existing) {
            if ($update) {
                if ($dry_run) {
                    return 'updated';
                }
                $this->updateOrderRecord($existing, $base_id, $rows, $user);
                return 'updated';
            }
            if ($force) {
                return 'skipped';
            }
            throw new \RuntimeException("Order already imported (session_id: {$base_id})");
        }

        if ($dry_run) {
            return 'imported';
        }

        $order = DB::transaction(function () use ($base_id, $rows, $user): LinkBuildingOrder {
            $order_data = $this->buildOrderData($base_id, $rows, $user);

            $order = new LinkBuildingOrder();
            $order->forceFill(['id' => Str::uuid()->toString(), ...$order_data]);
            $order->save();

            $this->createOrderItems($order->id, $rows);

            return $order;
        });

        // Generate the invoice from the order's just-imported total/items so a
        // newly imported legacy order always has a matching invoice right away.
        $this->legacy_invoice_service->syncForOrder($order->fresh(['items.drTier', 'user', 'orderCoupons.coupon']));

        return 'imported';
    }

    private function updateOrderRecord(LinkBuildingOrder $order, string $base_id, array $rows, User $user): void
    {
        DB::transaction(function () use ($order, $base_id, $rows, $user): void {
            $order_data = $this->buildOrderData($base_id, $rows, $user);

            $order->forceFill($order_data);
            $order->save();

            LinkBuildingOrderItem::where('order_id', $order->id)->delete();
            $this->createOrderItems($order->id, $rows);
        });

        // Re-syncing the invoice here is what keeps invoice totals from drifting
        // out of sync with the order whenever the legacy CSV is re-imported and
        // the order's total_amount or items change (e.g. due to reconciliation
        // against the transactions export, or corrected service/tier mapping).
        $this->legacy_invoice_service->syncForOrder($order->fresh(['items.drTier', 'user', 'orderCoupons.coupon']));
    }

    private function buildOrderData(string $base_id, array $rows, User $user): array
    {
        $first_row    = $rows[0];
        $status       = $this->mapStatus($first_row['status'] ?? '');
        $order_title  = $this->nullable($first_row['title'] ?? null);
        $created_at   = $this->parseDate($first_row['created_at'] ?? null) ?? now();
        $updated_at   = $this->parseDate($first_row['updated_at'] ?? null) ?? $created_at;
        $notes        = $this->buildOrderNotes($base_id, $first_row);
        // The legacy "price" column is already the line total (price * quantity), not a per-unit price.
        $total_amount = array_sum(array_map(
            fn (array $r) => $this->parseDecimal($r['price'] ?? '0'),
            $rows
        ));

        // Prefer the actual settled amount from the transactions export — it nets out
        // cancellations/refunds that never show up as their own row in the orders export.
        $reconciled_total = $this->reconcileTotalFromTransaction($base_id);
        if ($reconciled_total !== null) {
            $total_amount = $reconciled_total;
        }

        return [
            'user_id'                  => $user->id,
            'order_title'              => $order_title,
            'order_notes'              => $notes,
            'total_amount'             => $total_amount,
            'subtotal_before_discount' => $total_amount,
            'status'                   => $status,
            'session_id'               => $base_id,
            'session_title'            => $order_title,
            'is_legacy_import'         => true,
            'created_at'               => $created_at,
            'updated_at'               => $updated_at,
        ];
    }

    private function createOrderItems(string $order_id, array $rows): void
    {
        foreach ($rows as $item_row) {
            $service_name = trim($item_row['service'] ?? '');
            $dr_tier      = $this->resolveDrTier($service_name);
            $quantity     = max(1, (int) ($item_row['quantity'] ?? 1));
            // The legacy "price" column is already the line total (price * quantity), not a per-unit price.
            $line_total   = $this->parseDecimal($item_row['price'] ?? '0');
            $unit_price   = round($line_total / $quantity, 2);

            if ($dr_tier === null) {
                continue;
            }

            LinkBuildingOrderItem::create([
                'id'         => Str::uuid()->toString(),
                'order_id'   => $order_id,
                'dr_tier_id' => $dr_tier->id,
                'quantity'   => $quantity,
                'unit_price' => $unit_price,
                'subtotal'   => $line_total,
            ]);
        }
    }

    private function processCreditGroup(
        string $base_id,
        array $rows,
        User $user,
        bool $dry_run,
        bool $force,
        bool $update,
    ): string {
        $existing = CreditTransaction::where('user_id', $user->id)
            ->where('description', 'like', "%Legacy import%{$base_id}%")
            ->first();

        if ($existing) {
            if ($update) {
                if ($dry_run) {
                    return 'updated';
                }
                $first_row  = $rows[0];
                $title      = trim($first_row['title'] ?? '');
                $created_at = $this->parseDate($first_row['created_at'] ?? null) ?? now();
                $amount     = array_sum(array_map(
                    fn (array $r) => $this->parseDecimal($r['price'] ?? '0'),
                    $rows
                ));
                $existing->forceFill([
                    'amount'      => $amount,
                    'description' => "Legacy import: {$title} (ID: {$base_id})",
                    'created_at'  => $created_at,
                    'updated_at'  => $created_at,
                ]);
                $existing->save();
                return 'updated';
            }
            if ($force) {
                return 'skipped';
            }
            throw new \RuntimeException("Credit transaction already imported for order: {$base_id}");
        }

        if ($dry_run) {
            return 'credited';
        }

        $first_row  = $rows[0];
        $title      = trim($first_row['title'] ?? '');
        $created_at = $this->parseDate($first_row['created_at'] ?? null) ?? now();
        $amount     = array_sum(array_map(
            fn (array $r) => $this->parseDecimal($r['price'] ?? '0'),
            $rows
        ));

        CreditTransaction::create([
            'user_id'     => $user->id,
            'amount'      => $amount,
            'type'        => 'credit',
            'description' => "Legacy import: {$title} (ID: {$base_id})",
            'created_at'  => $created_at,
            'updated_at'  => $created_at,
        ]);

        return 'credited';
    }

    private function isCreditPurchase(array $rows): bool
    {
        foreach ($rows as $row) {
            $service = strtolower(trim($row['service'] ?? ''));
            $title   = strtolower(trim($row['title'] ?? ''));

            $mentions_credits  = str_contains($service, 'credits') || str_contains($title, 'credits');
            $is_tier_order     = (bool) preg_match('/^(?:base\s*-\s*)?d[ar]\s*\d+\+/i', $service);

            if ($mentions_credits && !$is_tier_order) {
                return true;
            }
        }

        return false;
    }

    private function resolveDrTier(string $service_name): ?DrTier
    {
        if ($service_name === '') {
            return null;
        }

        // Extract the tier number from "DR 40+", "DA 40+ (Credit)", or the
        // "BASE - DA 40+" / "BASE- DA 40+" legacy naming variant.
        if (!preg_match('/^(?:base\s*-\s*)?d[ar]\s*(\d+)\+/i', $service_name, $matches)) {
            return null;
        }

        $tier_number = $matches[1];
        $label       = "DR {$tier_number}+";

        return DrTier::where('label', $label)->first();
    }

    private function mapStatus(string $raw_status): string
    {
        $key = strtolower(trim($raw_status));
        return self::STATUS_MAP[$key] ?? 'pending';
    }

    private function buildOrderNotes(string $base_id, array $row): string
    {
        $parts = ["Legacy ID: {$base_id}"];

        if ($val = $this->nullable($row['date_started'] ?? null)) {
            $parts[] = "Date started: {$val}";
        }
        if ($val = $this->nullable($row['date_due'] ?? null)) {
            $parts[] = "Date due: {$val}";
        }
        if ($val = $this->nullable($row['date_completed'] ?? null)) {
            $parts[] = "Date completed: {$val}";
        }
        if ($val = $this->nullable($row['last_message_at'] ?? null)) {
            $parts[] = "Last message: {$val}";
        }
        if ($val = $this->nullable($row['invoice_paysys'] ?? null)) {
            $parts[] = "Payment system: {$val}";
        }
        if ($val = $this->nullable($row['invoice_currency'] ?? null)) {
            $parts[] = "Currency: {$val}";
        }
        if ($val = $this->nullable($row['employees'] ?? null)) {
            $parts[] = "Employees: {$val}";
        }

        return implode("\n", $parts);
    }

    private function parseCsv(string $file_path): ?array
    {
        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            $this->error("Cannot open file: {$file_path}");
            return null;
        }

        $rows         = [];
        $headers      = null;
        $skipped_rows = 0;

        while (($columns = fgetcsv($handle, 0, ',', '"')) !== false) {
            if ($headers === null) {
                $headers = array_map(
                    fn (string $h) => rtrim(trim(ltrim($h, "\xEF\xBB\xBF")), ':'),
                    $columns
                );
                continue;
            }

            $col_count    = \count($columns);
            $header_count = \count($headers);

            if ($col_count === 0 || $col_count === 1 && trim($columns[0]) === '') {
                continue; // blank line
            }

            if ($col_count < $header_count) {
                $columns = array_pad($columns, $header_count, '');
            } elseif ($col_count > $header_count) {
                $columns = \array_slice($columns, 0, $header_count);
            }

            $rows[] = array_combine($headers, $columns);
        }

        fclose($handle);

        if ($skipped_rows > 0) {
            $this->warn("Skipped {$skipped_rows} completely empty line(s).");
        }

        if (empty($rows)) {
            $this->error('The CSV file contains no data rows.');
            return null;
        }

        return $rows;
    }

    private function printFieldMapping(array $sample_row): void
    {
        $this->line('<fg=cyan>Field Mapping:</>');
        $this->newLine();

        $table_rows = [];
        foreach ($sample_row as $field => $value) {
            $target       = self::FIELD_MAPPING[$field] ?? null;
            $is_skipped   = in_array($field, self::SKIPPED_FIELDS, true);
            $status_label = $target
                ? '<fg=green>Mapped</>'
                : ($is_skipped ? '<fg=yellow>Skipped</>' : '<fg=red>Unknown</>');

            $table_rows[] = [$field, $target ?? '—', $status_label];
        }

        $this->table(['CSV Column', 'Target Field', 'Status'], $table_rows);
        $this->newLine();
    }

    private function printSummary(array $stats, array $errors, bool $dry_run): void
    {
        $header = $dry_run ? '=== [DRY RUN] Import Preview ===' : '=== Import Results ===';
        $this->line("<fg=cyan>{$header}</>");
        $this->newLine();

        $this->table(
            ['Result', 'Count'],
            [
                ['Imported (link-building orders)', $stats['imported']],
                ['Imported (credit transactions)',  $stats['credited']],
                ['Updated',                        $stats['updated']],
                ['Skipped',                        $stats['skipped']],
                ['Failed',                         $stats['failed']],
                ['Total',                          array_sum($stats)],
            ]
        );

        if (!empty($errors)) {
            $this->newLine();
            $this->error('Errors encountered:');
            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }
        }

        if ($dry_run) {
            $this->newLine();
            $this->warn('No data was saved. Remove --dry-run to execute the import.');
        }
    }

    private function parseDate(?string $value): ?Carbon
    {
        $trimmed = trim($value ?? '');
        if ($trimmed === '') {
            return null;
        }

        try {
            return Carbon::parse($trimmed);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseDecimal(?string $value): float
    {
        $cleaned = preg_replace('/[^0-9.]/', '', trim($value ?? ''));
        return (float) ($cleaned ?: '0');
    }

    /**
     * Like parseDecimal(), but preserves a leading "-" so refund amounts
     * (always stored as negative numbers in the transactions export) stay negative.
     */
    private function parseSignedDecimal(?string $value): float
    {
        $trimmed = trim($value ?? '');
        if ($trimmed === '') {
            return 0.0;
        }

        $is_negative = str_starts_with($trimmed, '-');
        $amount      = $this->parseDecimal($trimmed);

        return $is_negative ? -$amount : $amount;
    }

    private function nullable(?string $value): ?string
    {
        $trimmed = trim($value ?? '');
        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Loads the transactions export and indexes it for reconciliation:
     *  - transactions_by_id: exact legacy id -> row, used to find the settled total/subtotal
     *    for a given order (only the row whose id has no "_N" suffix represents the order itself —
     *    suffixed rows are usually separate renewal charges or refund/fee line items).
     *  - refund_by_charge: sum of refund amounts grouped by Stripe charge (transaction_id), so a
     *    refund recorded under a different row id can still be matched back to the original charge.
     */
    private function loadTransactions(string $file_path): void
    {
        if (!file_exists($file_path)) {
            $this->warn("Transactions file not found, skipping total reconciliation: {$file_path}");
            $this->newLine();
            return;
        }

        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            $this->warn("Cannot open transactions file, skipping total reconciliation: {$file_path}");
            $this->newLine();
            return;
        }

        $headers = null;

        while (($columns = fgetcsv($handle, 0, ',', '"')) !== false) {
            if ($headers === null) {
                $headers = array_map(
                    fn (string $h) => rtrim(trim(ltrim($h, "\xEF\xBB\xBF")), ':'),
                    $columns
                );
                continue;
            }

            $col_count    = \count($columns);
            $header_count = \count($headers);

            if ($col_count === 0 || $col_count === 1 && trim($columns[0]) === '') {
                continue;
            }

            if ($col_count < $header_count) {
                $columns = array_pad($columns, $header_count, '');
            } elseif ($col_count > $header_count) {
                $columns = \array_slice($columns, 0, $header_count);
            }

            $row = array_combine($headers, $columns);
            $id  = trim($row['id'] ?? '');

            if ($id !== '') {
                $this->transactions_by_id[$id] = $row;
            }

            $charge_id = trim($row['transaction_id'] ?? '');
            $refund    = $this->parseSignedDecimal($row['refund'] ?? null);

            if ($charge_id !== '' && $refund !== 0.0) {
                $this->refund_rows_by_charge[$charge_id][] = ['id' => $id, 'refund' => $refund];
            }
        }

        fclose($handle);
    }

    /**
     * Returns the settled total for an order, taken from its matching transaction
     * (net of any refund issued against the same Stripe charge), or null if no
     * matching transaction was found — callers should fall back to the orders export sum.
     */
    private function reconcileTotalFromTransaction(string $base_id): ?float
    {
        $transaction = $this->transactions_by_id[$base_id] ?? null;
        if ($transaction === null) {
            return null;
        }

        $total = $this->nullable($transaction['total'] ?? null) !== null
            ? $this->parseDecimal($transaction['total'])
            : $this->parseDecimal($transaction['subtotal'] ?? '0');

        $charge_id = trim($transaction['transaction_id'] ?? '');

        // A refund recorded on the matched row itself is already netted into its
        // total/subtotal — only apply refunds that live on a separate row (e.g. a
        // dedicated "Refund for #XYZ" line) sharing the same Stripe charge.
        $refund_adjustment = 0.0;
        foreach ($this->refund_rows_by_charge[$charge_id] ?? [] as $entry) {
            if ($entry['id'] !== $base_id) {
                $refund_adjustment += $entry['refund'];
            }
        }

        return max(0.0, round($total + $refund_adjustment, 2));
    }
}
