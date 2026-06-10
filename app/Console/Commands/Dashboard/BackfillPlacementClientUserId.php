<?php

namespace App\Console\Commands\Dashboard;

use App\Models\LinkBuildingOrderPlacement;
use Illuminate\Console\Command;

class BackfillPlacementClientUserId extends Command
{
    protected $signature   = 'lbo:backfill-client-user-id {--dry-run : Preview affected rows without writing changes}';
    protected $description = 'Backfills user_id on client-purchased placements (order_item_id set, user_id null) from the parent order\'s user.';

    public function handle(): int
    {
        $dry_run = (bool) $this->option('dry-run');

        $query = LinkBuildingOrderPlacement::with(['orderItem.order.user'])
            ->whereNotNull('order_item_id')
            ->whereNull('user_id');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No placements need backfilling.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} placement(s) to backfill." . ($dry_run ? ' (dry-run — no changes written)' : ''));

        $updated  = 0;
        $skipped  = 0;

        $query->chunk(500, function ($placements) use ($dry_run, &$updated, &$skipped) {
            foreach ($placements as $placement) {
                $user = $placement->orderItem?->order?->user;

                if (! $user) {
                    $this->warn("  Skipping placement {$placement->id} — no linked user found.");
                    $skipped++;
                    continue;
                }

                if (! $dry_run) {
                    $placement->update(['user_id' => $user->id]);
                }

                $updated++;
            }
        });

        $action = $dry_run ? 'Would update' : 'Updated';
        $this->info("{$action} {$updated} placement(s). Skipped {$skipped}.");

        return self::SUCCESS;
    }
}
