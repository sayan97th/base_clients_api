<?php

namespace Tests\Feature\LinkBuilding\History;

use App\Jobs\RestoreLinkBuildingOrderHistoryJob;
use App\Models\LinkBuildingOrderPlacementRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * Restoring a large action (an import over thousands of rows) runs on the queue
 * so the request cannot time out. The panel polls its status until it is done.
 * The test queue runs jobs right away, so a queued restore is finished by the
 * time the status is read.
 */
class HistoryQueuedRestoreTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
        config(['link_building.history_sync_restore_max_revisions' => 1]);
    }

    private function statusOf(string $restore_id)
    {
        return $this->actingAs($this->admin, 'api')->getJson("/api/admin/link-building-orders/history/restores/{$restore_id}");
    }

    public function test_a_large_action_is_restored_on_the_queue(): void
    {
        $first  = $this->placement(['keyword' => 'one']);
        $second = $this->placement(['keyword' => 'two']);
        $this->pasteOver([$first->id => ['keyword' => 'x'], $second->id => ['keyword' => 'y']]);

        $restore_id = $this->restore(['batch_id' => $this->lastBatchId()], 202)
            ->assertJsonPath('queued', true)
            ->json('restore_id');

        $this->statusOf($restore_id)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.applied', true)
            ->assertJsonPath('result.restored_cells', 2)
            ->assertJsonMissingPath('user_id');

        $this->assertSame('one', $first->fresh()->keyword);
        $this->assertSame('two', $second->fresh()->keyword);

        $this->assertSame(
            $this->admin->id,
            LinkBuildingOrderPlacementRevision::where('source', 'restore')->value('user_id'),
            'The restore made on the queue is recorded under the admin who started it.'
        );
    }

    public function test_the_job_receives_the_batch_and_the_strategy(): void
    {
        Queue::fake();
        $first  = $this->placement();
        $second = $this->placement();
        $this->pasteOver([$first->id => ['keyword' => 'x'], $second->id => ['keyword' => 'y']]);

        $restore_id = $this->restore(['batch_id' => $this->lastBatchId(), 'conflict_strategy' => 'skip'], 202)->json('restore_id');

        Queue::assertPushed(RestoreLinkBuildingOrderHistoryJob::class, 1);
        $this->statusOf($restore_id)->assertOk()->assertJsonPath('status', 'queued');
    }

    public function test_conflicts_found_on_the_queue_are_returned_without_restoring(): void
    {
        $first  = $this->placement(['keyword' => 'one']);
        $second = $this->placement(['keyword' => 'two']);
        $this->pasteOver([$first->id => ['keyword' => 'x'], $second->id => ['keyword' => 'y']]);
        $paste_batch_id = $this->lastBatchId();
        $this->pasteOver([$first->id => ['keyword' => 'fixed']], source: 'edit');

        $restore_id = $this->restore(['batch_id' => $paste_batch_id], 202)->json('restore_id');

        $this->statusOf($restore_id)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.applied', false)
            ->assertJsonPath('result.conflicts_count', 1);

        $this->assertSame('y', $second->fresh()->keyword);
    }

    public function test_a_single_row_change_is_always_restored_right_away(): void
    {
        config(['link_building.history_sync_restore_max_revisions' => 0]);
        $placement = $this->placement(['keyword' => 'one']);
        $this->pasteOver([$placement->id => ['keyword' => 'x']]);

        $this->restore(['revision_id' => LinkBuildingOrderPlacementRevision::where('event', 'updated')->value('id')])
            ->assertJsonPath('applied', true);
    }

    public function test_a_failed_restore_says_so(): void
    {
        $job = new RestoreLinkBuildingOrderHistoryJob('r-failed', 'batch-x', 'abort', $this->admin->id, null);
        RestoreLinkBuildingOrderHistoryJob::markQueued('r-failed', $this->admin->id);

        $job->failed(new RuntimeException('database went away'));

        $this->statusOf('r-failed')
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'The restore could not be completed. Check the history before trying again.');
    }

    public function test_only_the_admin_who_started_a_queued_restore_can_read_it(): void
    {
        RestoreLinkBuildingOrderHistoryJob::markQueued('r-mine', $this->admin->id);

        $this->actingAs($this->makeUser('admin'), 'api')
            ->getJson('/api/admin/link-building-orders/history/restores/r-mine')
            ->assertNotFound();
    }

    public function test_an_unknown_or_expired_restore_is_not_found(): void
    {
        $this->statusOf('missing')->assertNotFound();

        RestoreLinkBuildingOrderHistoryJob::markQueued('r-expired', $this->admin->id);
        Cache::forget(RestoreLinkBuildingOrderHistoryJob::cacheKey('r-expired'));

        $this->statusOf('r-expired')->assertNotFound();
    }
}
