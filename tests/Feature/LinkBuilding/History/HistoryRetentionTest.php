<?php

namespace Tests\Feature\LinkBuilding\History;

use App\Models\LinkBuildingOrderHistoryBatch;
use App\Models\LinkBuildingOrderPlacementRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * Keeps the edit history from growing forever: changes older than the retention
 * window are pruned every day, and the action summaries the Version history list
 * reads are built for revisions recorded before the summaries existed.
 */
class HistoryRetentionTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
    }

    public function test_changes_older_than_the_retention_window_are_pruned(): void
    {
        config(['link_building.history_retention_days' => 30]);
        $placement = $this->placement();

        $this->travelTo(now()->subDays(31));
        $this->pasteOver([$placement->id => ['keyword' => 'old change']]);
        $old_batch_id = $this->lastBatchId();
        $this->travelBack();

        $this->pasteOver([$placement->id => ['keyword' => 'recent change']]);
        $recent_batch_id = $this->lastBatchId();

        $this->artisan('model:prune', ['--model' => [LinkBuildingOrderPlacementRevision::class, LinkBuildingOrderHistoryBatch::class]])
            ->assertSuccessful();

        $this->assertFalse(LinkBuildingOrderPlacementRevision::where('batch_id', $old_batch_id)->exists());
        $this->assertFalse(LinkBuildingOrderHistoryBatch::where('batch_id', $old_batch_id)->exists());
        $this->assertTrue(LinkBuildingOrderPlacementRevision::where('batch_id', $recent_batch_id)->exists());
        $this->assertTrue(LinkBuildingOrderHistoryBatch::where('batch_id', $recent_batch_id)->exists());
    }

    public function test_pruning_is_scheduled_daily_for_both_history_tables(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('model:prune')
            ->assertSuccessful();

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'model:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
        $this->assertStringContainsString('LinkBuildingOrderPlacementRevision', $events->first()->command);
        $this->assertStringContainsString('LinkBuildingOrderHistoryBatch', $events->first()->command);
    }

    public function test_the_migration_builds_summaries_for_revisions_recorded_before_them(): void
    {
        $now = now()->toDateTimeString();
        $revision = fn (array $values) => [
            'placement_id' => 'p1', 'order_id' => 'BL-1', 'event' => 'updated', 'source' => 'paste',
            'cell_changes' => json_encode(['keyword' => ['old' => 'a', 'new' => 'b']]), 'snapshot' => null,
            'restored_batch_id' => null, 'user_id' => $this->admin->id, 'created_at' => $now, ...$values,
        ];

        DB::table('link_building_order_placement_revisions')->insert([
            $revision(['batch_id' => 'old-paste']),
            $revision(['batch_id' => 'old-paste', 'placement_id' => 'p2', 'order_id' => 'BL-2']),
            $revision(['batch_id' => 'old-restore', 'source' => 'restore', 'restored_batch_id' => 'old-paste']),
        ]);

        $migration = require database_path('migrations/2026_10_24_000003_create_link_building_order_history_batches_table.php');
        (fn () => $this->backfillBatches())->call($migration);

        $paste = LinkBuildingOrderHistoryBatch::where('batch_id', 'old-paste')->sole();
        $this->assertSame('paste', $paste->source);
        $this->assertSame(2, $paste->revisions_count);
        $this->assertSame(2, $paste->rows_count);
        $this->assertSame(2, $paste->cells_count);
        $this->assertSame(['BL-1', 'BL-2'], $paste->order_ids_preview);
        $this->assertNotNull($paste->restored_at);
        $this->assertSame($this->admin->id, $paste->restored_by_id);

        $this->assertSame('old-paste', LinkBuildingOrderHistoryBatch::where('batch_id', 'old-restore')->value('restored_batch_id'));
    }
}
