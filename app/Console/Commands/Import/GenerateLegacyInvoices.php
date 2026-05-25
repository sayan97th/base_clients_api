<?php

namespace App\Console\Commands\Import;

use App\Models\Invoice;
use App\Models\InvoiceCouponDiscount;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateLegacyInvoices extends Command
{
    protected $signature = 'invoices:generate-legacy
                            {--all    : Generate invoices for all legacy orders, including those that already have one}
                            {--update : Update existing invoices instead of skipping them}
                            {--dry-run : Preview the generation without saving any data}';

    protected $description = 'Generate invoice records for legacy-imported link-building orders';

    private const ORDER_STATUS_TO_INVOICE_STATUS = [
        'completed'       => 'paid',
        'processing'      => 'unpaid',
        'pending'         => 'unpaid',
        'payment_pending' => 'unpaid',
        'cancelled'       => 'void',
    ];

    public function handle(): int
    {
        $all     = (bool) $this->option('all');
        $update  = (bool) $this->option('update');
        $dry_run = (bool) $this->option('dry-run');

        $this->info('=== Legacy Invoice Generation ===');
        $this->newLine();

        if ($dry_run) {
            $this->warn('[DRY RUN] No data will be saved to the database.');
            $this->newLine();
        }

        $query = LinkBuildingOrder::where('is_legacy_import', true)->with(['items.drTier', 'user', 'orderCoupons.coupon']);

        if (!$all && !$update) {
            $query->whereDoesntHave('invoice');
        }

        $orders = $query->get();
        $total  = $orders->count();

        if ($total === 0) {
            $this->info('No legacy orders found to process.');
            return self::SUCCESS;
        }

        $this->line("Found <fg=yellow>{$total}</> legacy order(s) to process.");
        $this->newLine();

        if (!$this->confirm('Proceed with invoice generation?', true)) {
            $this->info('Generation cancelled.');
            return self::SUCCESS;
        }

        $this->newLine();

        $stats  = ['generated' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($orders as $order) {
            try {
                $result          = $this->processOrder($order, $dry_run, $update);
                $stats[$result]++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $errors[] = "Order [{$order->session_id}] [user_id: {$order->user_id}]: {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->printSummary($stats, $errors, $dry_run);

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processOrder(LinkBuildingOrder $order, bool $dry_run, bool $update): string
    {
        $existing = Invoice::where('order_id', $order->id)->first();

        if ($existing) {
            if ($update) {
                if ($dry_run) {
                    return 'updated';
                }
                $this->updateInvoice($existing, $order);
                return 'updated';
            }
            return 'skipped';
        }

        if ($dry_run) {
            return 'generated';
        }

        $this->createInvoice($order);
        return 'generated';
    }

    private function createInvoice(LinkBuildingOrder $order): void
    {
        $user            = $order->user;
        $invoice_status  = $this->resolveInvoiceStatus($order->status);
        $subtotal_amount = (float) $order->items->sum('subtotal');
        $total_amount    = (float) $order->total_amount;
        $discount_amount = (float) ($order->coupon_discount_amount ?? 0.0);
        $date_issued     = $order->created_at ?? now();
        $date_paid       = $invoice_status === 'paid' ? $date_issued : null;

        DB::transaction(function () use (
            $order, $user, $invoice_status,
            $subtotal_amount, $total_amount, $discount_amount,
            $date_issued, $date_paid
        ): void {
            $unique_id      = strtoupper(bin2hex(random_bytes(4)));
            $invoice_number = 'BSM-' . str_pad(Invoice::count() + 1, 4, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'unique_id'       => $unique_id,
                'invoice_number'  => $invoice_number,
                'user_id'         => $user->id,
                'order_id'        => $order->id,
                'session_id'      => $order->session_id,
                'session_title'   => $order->session_title,
                'status'          => $invoice_status,
                'payment_method'  => $invoice_status === 'paid' ? 'Account Balance' : 'Pending',
                'currency_type'   => 'usd',
                'subtotal_amount' => $subtotal_amount,
                'discount_amount' => $discount_amount,
                'discount_type'   => $discount_amount > 0 ? 'legacy' : null,
                'total_amount'    => $total_amount,
                'credit_amount'   => 0.0,
                'notes'           => $order->order_notes,
                'date_issued'     => $date_issued,
                'date_due'        => $date_issued,
                'date_paid'       => $date_paid,
            ]);

            $this->createLineItems($invoice, $order);
            $this->createCouponDiscounts($invoice, $order);
        });
    }

    private function updateInvoice(Invoice $invoice, LinkBuildingOrder $order): void
    {
        $invoice_status  = $this->resolveInvoiceStatus($order->status);
        $subtotal_amount = (float) $order->items->sum('subtotal');
        $total_amount    = (float) $order->total_amount;
        $discount_amount = (float) ($order->coupon_discount_amount ?? 0.0);
        $date_issued     = $order->created_at ?? now();
        $date_paid       = $invoice_status === 'paid' ? $date_issued : null;

        DB::transaction(function () use (
            $invoice, $order, $invoice_status,
            $subtotal_amount, $total_amount, $discount_amount,
            $date_issued, $date_paid
        ): void {
            $invoice->forceFill([
                'session_id'      => $order->session_id,
                'session_title'   => $order->session_title,
                'status'          => $invoice_status,
                'payment_method'  => $invoice_status === 'paid' ? 'Account Balance' : 'Pending',
                'subtotal_amount' => $subtotal_amount,
                'discount_amount' => $discount_amount,
                'discount_type'   => $discount_amount > 0 ? 'legacy' : null,
                'total_amount'    => $total_amount,
                'notes'           => $order->order_notes,
                'date_issued'     => $date_issued,
                'date_due'        => $date_issued,
                'date_paid'       => $date_paid,
            ]);
            $invoice->save();

            $invoice->lineItems()->delete();
            $invoice->couponDiscounts()->delete();

            $this->createLineItems($invoice, $order);
            $this->createCouponDiscounts($invoice, $order);
        });
    }

    private function createLineItems(Invoice $invoice, LinkBuildingOrder $order): void
    {
        foreach ($order->items as $item) {
            $item_name = $item->drTier
                ? $item->drTier->label . ' Link Building'
                : 'Link Building Service';

            $invoice->lineItems()->create([
                'order_id'     => $order->id,
                'item_name'    => $item_name,
                'product_type' => 'link_building',
                'price'        => $item->unit_price,
                'quantity'     => $item->quantity,
                'item_total'   => $item->subtotal,
            ]);
        }
    }

    private function createCouponDiscounts(Invoice $invoice, LinkBuildingOrder $order): void
    {
        foreach ($order->orderCoupons as $order_coupon) {
            $coupon = $order_coupon->coupon;

            if (!$coupon) {
                continue;
            }

            InvoiceCouponDiscount::create([
                'invoice_id'      => $invoice->id,
                'code'            => $coupon->code,
                'name'            => $coupon->name ?? null,
                'discount_type'   => $coupon->discount_type,
                'discount_value'  => $coupon->discount_value,
                'discount_amount' => $order_coupon->discount_amount,
            ]);
        }
    }

    private function resolveInvoiceStatus(string $order_status): string
    {
        return self::ORDER_STATUS_TO_INVOICE_STATUS[$order_status] ?? 'unpaid';
    }

    private function printSummary(array $stats, array $errors, bool $dry_run): void
    {
        $header = $dry_run ? '=== [DRY RUN] Generation Preview ===' : '=== Generation Results ===';
        $this->line("<fg=cyan>{$header}</>");
        $this->newLine();

        $this->table(
            ['Result', 'Count'],
            [
                ['Generated', $stats['generated']],
                ['Updated',   $stats['updated']],
                ['Skipped',   $stats['skipped']],
                ['Failed',    $stats['failed']],
                ['Total',     array_sum($stats)],
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
            $this->warn('No data was saved. Remove --dry-run to execute the generation.');
        }
    }
}
