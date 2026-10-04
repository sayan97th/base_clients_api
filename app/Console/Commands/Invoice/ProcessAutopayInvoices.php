<?php

namespace App\Console\Commands\Invoice;

use App\Services\AutopayService;
use Illuminate\Console\Command;

class ProcessAutopayInvoices extends Command
{
    protected $signature = 'invoices:process-autopay
                            {--dry-run : List the invoices that would be charged without charging anything}
                            {--invoice= : Only process the invoice with this unique_id}';

    protected $description = 'Charge due invoices to the saved card of clients who enabled autopay, and reconcile interrupted charges';

    public function handle(AutopayService $autopay_service): int
    {
        $is_dry_run = (bool) $this->option('dry-run');

        $summary = $autopay_service->runDueCharges($is_dry_run, $this->option('invoice') ?: null);

        if (! empty($summary['candidates'])) {
            $this->table(
                ['Invoice', 'Client', 'Amount', 'Action'],
                array_map(fn ($row) => [
                    $row['invoice_number'],
                    $row['client'],
                    '$' . number_format($row['amount'], 2),
                    $row['action'],
                ], $summary['candidates'])
            );
        }

        if ($is_dry_run) {
            $this->info('Dry run — no charges were made. ' . count($summary['candidates']) . ' invoice(s) eligible.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Autopay finished: %d charged, %d failed, %d skipped, %d pending, %d reconciled.',
            $summary['charged'],
            $summary['failed'],
            $summary['skipped'],
            $summary['pending'],
            $summary['reconciled'],
        ));

        return self::SUCCESS;
    }
}
