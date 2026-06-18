<?php

namespace App\Console\Commands\Import;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * php artisan transactions:import
 * php artisan transactions:import --dry-run
 * php artisan transactions:import --force
 * php artisan transactions:import path/to/custom.csv
 */
class ImportLegacyTransactions extends Command
{
    protected $signature = 'transactions:import
                            {file? : Path to the CSV file (defaults to import/transactions/base-transactions.csv)}
                            {--dry-run : Preview the import without saving any data}
                            {--force  : Overwrite existing records with the same session_id}
                            {--skip-unknown-users : Skip rows whose user cannot be resolved}';

    protected $description = 'Import legacy transactions from a CSV export file into the transactions table';

    /** Maps paysys values to Transaction payment_method enum values. */
    private const PAYSYS_MAP = [
        'stripe'          => 'credit_card',
        'manual'          => 'credit_card',
        'account balance' => 'account_credits',
    ];

    public function handle(): int
    {
        $file    = $this->argument('file')
            ?? base_path('import/transactions/base-transactions.csv');
        $dry_run = $this->option('dry-run');
        $force   = $this->option('force');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $this->info($dry_run ? '[DRY RUN] No data will be saved.' : 'Importing legacy transactions…');

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $this->error("Cannot open file: {$file}");
            return self::FAILURE;
        }

        $headers = fgetcsv($handle);
        if ($headers === false || empty($headers)) {
            $this->error('CSV file is empty or has no headers.');
            fclose($handle);
            return self::FAILURE;
        }

        $headers = array_map('trim', $headers);

        $imported  = 0;
        $skipped   = 0;
        $overwritten = 0;
        $errors    = 0;
        $row_index = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $row_index++;

            if (count($row) < count($headers)) {
                $this->warn("Row {$row_index}: column count mismatch — skipping.");
                $errors++;
                continue;
            }

            $data = array_combine($headers, array_map('trim', $row));

            // Use BSM number as the legacy session identifier
            $bsm_number = $data['number'] ?? null;

            if (empty($bsm_number)) {
                $this->warn("Row {$row_index}: missing 'number' field — skipping.");
                $errors++;
                continue;
            }

            // Skip duplicate unless --force
            $existing = Transaction::where('session_id', $bsm_number)->first();
            if ($existing && ! $force) {
                $skipped++;
                continue;
            }

            // Resolve user
            $user_id = null;
            $email   = $data['email'] ?? '';

            if (! empty($data['user_id'])) {
                $user    = User::find((int) $data['user_id']);
                $user_id = $user?->id;
            }

            if ($user_id === null && ! empty($email)) {
                $user    = User::where('email', $email)->first();
                $user_id = $user?->id;
            }

            if ($user_id === null && $this->option('skip-unknown-users')) {
                $this->warn("Row {$row_index} ({$bsm_number}): user not found — skipping.");
                $skipped++;
                continue;
            }

            // Map payment system to payment_method
            $paysys         = strtolower(trim($data['paysys'] ?? ''));
            $payment_method = self::PAYSYS_MAP[$paysys] ?? 'credit_card';
            $currency       = strtoupper(trim($data['currency'] ?? 'USD'));

            // Credits (CRD currency or Account Balance) → credit_payment
            $is_credits = $currency === 'CRD' || $payment_method === 'account_credits';
            $type       = $is_credits ? 'credit_payment' : 'purchase';

            // Amount: prefer 'total', fall back to 'subtotal'
            $total  = (float) ($data['total']    ?? 0);
            $credit = (float) ($data['credit']   ?? 0);
            $amount = $total > 0 ? $total : $credit;

            // Stripe charge ID
            $payment_intent_id = ! empty($data['transaction_id'])
                ? $data['transaction_id']
                : null;

            // Description from items field
            $items       = $data['items'] ?? null;
            $description = $items ? "Legacy import: {$items}" : "Legacy import: {$bsm_number}";

            // Billing name for session title
            $first      = trim($data['billing_name_f'] ?? '');
            $last       = trim($data['billing_name_l'] ?? '');
            $company    = trim($data['billing_company'] ?? '');
            $name_parts = array_filter([$first, $last]);
            $display    = implode(' ', $name_parts) ?: $company ?: $email;
            $session_title = $display
                ? "{$bsm_number} — {$display}"
                : $bsm_number;

            // Prefer date_paid as the authoritative timestamp, fall back to created_at
            $raw_date = ! empty($data['date_paid']) ? $data['date_paid'] : ($data['created_at'] ?? null);
            try {
                $created_at = $raw_date ? Carbon::parse($raw_date) : now();
            } catch (Throwable) {
                $created_at = now();
            }

            $payload = [
                'user_id'           => $user_id,
                'type'              => $type,
                'status'            => 'success',
                'amount'            => $amount,
                'payment_method'    => $payment_method,
                'payment_intent_id' => $payment_intent_id,
                'session_id'        => $bsm_number,
                'session_title'     => $session_title,
                'invoice_id'        => $bsm_number,
                'description'       => mb_substr($description, 0, 500),
                'metadata'          => [
                    'source'           => 'legacy_csv_import',
                    'bsm_number'       => $bsm_number,
                    'legacy_id'        => $data['id'] ?? null,
                    'email'            => $email ?: null,
                    'billing_company'  => $company ?: null,
                    'billing_country'  => $data['billing_country'] ?? null,
                    'subtotal'         => (float) ($data['subtotal'] ?? 0),
                    'discount'         => (float) ($data['discount'] ?? 0),
                    'refund'           => (float) ($data['refund']   ?? 0),
                    'currency'         => $currency,
                    'coupon_id'        => ! empty($data['coupon_id']) ? $data['coupon_id'] : null,
                    'original_paysys'  => $data['paysys'] ?? null,
                ],
                'created_at' => $created_at,
                'updated_at' => $created_at,
            ];

            if ($dry_run) {
                $this->line("[DRY RUN] Would import {$bsm_number} (user_id={$user_id}, amount={$amount}, type={$type})");
                $imported++;
                continue;
            }

            try {
                DB::transaction(function () use ($existing, $payload, $force, &$overwritten, &$imported) {
                    if ($existing && $force) {
                        $existing->update($payload);
                        $overwritten++;
                    } else {
                        Transaction::create($payload);
                        $imported++;
                    }
                });
            } catch (Throwable $e) {
                $this->error("Row {$row_index} ({$bsm_number}): {$e->getMessage()}");
                $errors++;
            }
        }

        fclose($handle);

        $this->newLine();
        $this->info("Import complete.");
        $this->table(
            ['Imported', 'Overwritten', 'Skipped', 'Errors'],
            [[$imported, $overwritten, $skipped, $errors]]
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
