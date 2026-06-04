<?php

namespace App\Console\Commands;

use App\Models\LinkBuildingOrderPlacement;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillLinkBuildingDeliveryDates extends Command
{
    protected $signature = 'link-building:backfill-delivery-dates
                            {--dry-run : Preview how many records would be updated without saving}';

    protected $description = 'Backfills estimated_delivery_date (request_date + 30 days) for placements that are missing it. Skips records that already have a delivery date.';

    public function handle(): int
    {
        $is_dry_run = $this->option('dry-run');

        $query = LinkBuildingOrderPlacement::where(function ($q) {
            $q->whereNull('estimated_delivery_date')
              ->orWhere('estimated_delivery_date', '');
        });

        $total = $query->count();

        if ($total === 0) {
            $this->info('All records already have a delivery date. Nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} record(s) missing a delivery date.");

        if ($is_dry_run) {
            $this->warn('Dry-run mode — no changes will be saved.');
            return self::SUCCESS;
        }

        if (! $this->confirm("Backfill delivery date for {$total} record(s)? Records with an existing date will NOT be touched.")) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        $bar      = $this->output->createProgressBar($total);
        $updated  = 0;
        $skipped  = 0;

        $bar->start();

        $query->chunk(200, function ($placements) use ($bar, &$updated, &$skipped) {
            foreach ($placements as $placement) {
                $delivery_date = $this->calculateDeliveryDate($placement);

                if ($delivery_date === null) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $placement->timestamps = false;
                $placement->estimated_delivery_date = $delivery_date;
                $placement->save();

                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info("Done. Updated: {$updated} | Skipped (no parseable date): {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Returns estimated_delivery_date as a m/d/Y string (request_date + 30 days).
     * Falls back to created_at + 30 days when request_date is missing or unparseable.
     * Returns null when no base date can be determined.
     */
    private function calculateDeliveryDate(LinkBuildingOrderPlacement $placement): ?string
    {
        if (! empty($placement->request_date)) {
            try {
                return Carbon::createFromFormat('m/d/Y', $placement->request_date)
                    ->addDays(30)
                    ->format('m/d/Y');
            } catch (\Exception) {
                // Fall through to created_at
            }
        }

        if ($placement->created_at) {
            return Carbon::instance($placement->created_at)
                ->addDays(30)
                ->format('m/d/Y');
        }

        return null;
    }
}
