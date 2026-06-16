<?php

namespace App\Console\Commands\Import;

use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Services\LegacyInvoiceService;
use Illuminate\Console\Command;
use Throwable;

class GenerateLegacyInvoices extends Command
{
    # php artisan invoices:generate-legacy --update
    protected $signature = 'invoices:generate-legacy
                            {--all    : Generate invoices for all legacy orders, including those that already have one}
                            {--update : Update existing invoices instead of skipping them}
                            {--dry-run : Preview the generation without saving any data}';

    protected $description = 'Generate invoice records for legacy-imported link-building orders';

    public function __construct(private readonly LegacyInvoiceService $legacy_invoice_service)
    {
        parent::__construct();
    }

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
                $this->legacy_invoice_service->refresh($existing, $order);
                return 'updated';
            }
            return 'skipped';
        }

        if ($dry_run) {
            return 'generated';
        }

        $this->legacy_invoice_service->generate($order);
        return 'generated';
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
