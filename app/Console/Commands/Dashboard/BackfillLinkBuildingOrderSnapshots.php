<?php

namespace App\Console\Commands\Dashboard;

use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderSnapshot;
use App\Services\LinkBuildingOrderSnapshotService;
use Illuminate\Console\Command;

/**
 * Gives every Link Building order placed before original order copies existed
 * a "placed" copy built from the data it has now. These copies are flagged as
 * backfilled in the Original Order view, since the order may already have been
 * edited when they were taken. Safe to run more than once: orders that already
 * have a copy are skipped.
 */
class BackfillLinkBuildingOrderSnapshots extends Command
{
    protected $signature = 'link-building:backfill-order-snapshots
                            {--dry-run : Preview how many orders would get a copy without saving}';

    protected $description = 'Creates the read only "Original Order" copy for Link Building orders placed before copies were kept at checkout.';

    public function handle(LinkBuildingOrderSnapshotService $order_snapshots): int
    {
        $query = LinkBuildingOrder::whereDoesntHave(
            'snapshots',
            fn ($q) => $q->where('type', LinkBuildingOrderSnapshot::TYPE_PLACED)
        );

        $total = $query->count();

        if ($total === 0) {
            $this->info('Every Link Building order already has an original copy. Nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} order(s) without an original copy.");

        if ($this->option('dry-run')) {
            $this->warn('Dry-run mode, no copies will be saved.');
            return self::SUCCESS;
        }

        $bar     = $this->output->createProgressBar($total);
        $created = 0;

        $bar->start();

        $query->chunkById(100, function ($orders) use ($order_snapshots, $bar, &$created) {
            foreach ($orders as $order) {
                if ($order_snapshots->backfill($order)) {
                    $created++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. {$created} original order copy(ies) created.");

        return self::SUCCESS;
    }
}
