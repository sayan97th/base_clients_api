<?php

namespace Tests\Feature\LinkBuilding\History;

use App\Events\LinkBuildingOrderCreated;
use App\Events\LinkBuildingOrdersBulkChanged;
use App\Events\LinkBuildingOrderUpdated;
use App\Models\LinkBuildingOrderHistoryBatch;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderPlacementRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * Restoring changes from the edit history: a whole action or one row change goes
 * back to its previous values, cells edited again since are never overwritten
 * without asking, deleted rows come back, and restoring never deletes rows.
 */
class HistoryRestoreTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
    }

    /** The reported incident: Weave rows pasted over with Mather rows, then restored in one step. */
    public function test_restoring_a_paste_puts_every_overwritten_cell_back(): void
    {
        $first  = $this->placement();
        $second = $this->placement([
            'keyword'      => 'dental receptionist training, dental front office training',
            'landing_page' => 'https://www.getweave.com/dental-front-office-training/',
            'exact_match'  => false,
        ]);

        $this->pasteOver([
            $first->id  => ['client' => 'Mather', 'keyword' => 'senior living', 'landing_page' => 'https://mather.com/a', 'exact_match' => 'No'],
            $second->id => ['client' => 'Mather', 'keyword' => 'retirement', 'landing_page' => 'https://mather.com/b', 'exact_match' => 'Yes'],
        ]);
        $paste_batch_id = $this->lastBatchId();

        $this->restore(['batch_id' => $paste_batch_id])
            ->assertJsonPath('applied', true)
            ->assertJsonPath('restored_cells', 8)
            ->assertJsonPath('reload_required', false)
            ->assertJsonCount(2, 'data');

        $first->refresh();
        $second->refresh();
        $this->assertSame('Weave', $first->client);
        $this->assertSame('other fatigue icd 10', $first->keyword);
        $this->assertSame('https://www.getweave.com/fatigue-icd-10/', $first->landing_page);
        $this->assertTrue($first->exact_match);
        $this->assertSame('dental receptionist training, dental front office training', $second->keyword);
        $this->assertFalse($second->exact_match);

        // The restore is itself in the history, linked to the paste it restored.
        $this->assertSame(2, LinkBuildingOrderPlacementRevision::where('source', 'restore')->where('restored_batch_id', $paste_batch_id)->count());
        $summary = LinkBuildingOrderHistoryBatch::where('batch_id', $paste_batch_id)->sole();
        $this->assertNotNull($summary->restored_at);
        $this->assertSame($this->admin->id, $summary->restored_by_id);
    }

    public function test_a_restore_can_itself_be_restored(): void
    {
        $placement = $this->placement(['keyword' => 'original']);
        $this->pasteOver([$placement->id => ['keyword' => 'pasted']]);

        $this->restore(['batch_id' => $this->lastBatchId()]);
        $this->assertSame('original', $placement->fresh()->keyword);

        $this->restore(['batch_id' => $this->lastBatchId()]);
        $this->assertSame('pasted', $placement->fresh()->keyword);
    }

    public function test_an_action_keeps_the_time_it_was_first_restored(): void
    {
        $placement = $this->placement(['keyword' => 'original']);
        $this->pasteOver([$placement->id => ['keyword' => 'pasted']]);
        $paste_batch_id = $this->lastBatchId();

        $this->travelTo(now()->subHour());
        $this->restore(['batch_id' => $paste_batch_id]);
        $this->travelBack();
        $first_restore = LinkBuildingOrderHistoryBatch::where('batch_id', $paste_batch_id)->value('restored_at');

        $this->restore(['batch_id' => $paste_batch_id, 'conflict_strategy' => 'overwrite']);

        $this->assertEquals($first_restore, LinkBuildingOrderHistoryBatch::where('batch_id', $paste_batch_id)->value('restored_at'));
    }

    public function test_restoring_puts_a_replaced_formula_back(): void
    {
        $placement = $this->placement([
            'final_price'   => '$20.00',
            'cell_formulas' => ['final_price' => '=[posting_fee_lbs:~0]*2'],
        ]);
        $this->pasteOver([$placement->id => ['final_price' => '$99.00']]);

        // The dashboard removes the formula of a pasted cell, like pasting values in a sheet.
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'change_source' => 'paste',
                'updates'       => [['id' => $placement->id, 'fields' => [], 'cell_formulas' => null]],
            ])->assertOk();

        $this->restore(['batch_id' => $this->lastBatchId()]);

        $this->assertSame(['final_price' => '=[posting_fee_lbs:~0]*2'], $placement->fresh()->cell_formulas);
    }

    // ─── Conflicts ────────────────────────────────────────────────────────────

    public function test_restore_stops_when_a_restored_cell_was_edited_again(): void
    {
        $placement = $this->placement(['keyword' => 'original', 'client' => 'Weave']);

        $this->pasteOver([$placement->id => ['keyword' => 'pasted', 'client' => 'Mather']]);
        $paste_batch_id = $this->lastBatchId();

        // Someone fixes the keyword by hand afterwards.
        $this->pasteOver([$placement->id => ['keyword' => 'fixed by hand']], source: 'edit');

        $this->restore(['batch_id' => $paste_batch_id], 409)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('conflicts_count', 1)
            ->assertJsonPath('conflicts.0.field', 'keyword')
            ->assertJsonPath('conflicts.0.current_value', 'fixed by hand')
            ->assertJsonPath('conflicts.0.expected_value', 'pasted')
            ->assertJsonPath('conflicts.0.restore_value', 'original');

        $this->assertSame('Mather', $placement->fresh()->client, 'Nothing is restored while there are conflicts.');
        $this->assertNull(LinkBuildingOrderHistoryBatch::where('batch_id', $paste_batch_id)->value('restored_at'));

        // Skipping the conflicts restores the other cells only.
        $this->restore(['batch_id' => $paste_batch_id, 'conflict_strategy' => 'skip'])
            ->assertJsonPath('restored_cells', 1);

        $this->assertSame('Weave', $placement->fresh()->client);
        $this->assertSame('fixed by hand', $placement->fresh()->keyword);

        // Overwriting puts the keyword back as well.
        $this->restore(['batch_id' => $paste_batch_id, 'conflict_strategy' => 'overwrite']);

        $this->assertSame('original', $placement->fresh()->keyword);
    }

    public function test_a_cell_already_back_to_its_old_value_is_neither_a_conflict_nor_restored_again(): void
    {
        $placement = $this->placement(['keyword' => 'original']);
        $this->pasteOver([$placement->id => ['keyword' => 'pasted']]);
        $paste_batch_id = $this->lastBatchId();
        $this->pasteOver([$placement->id => ['keyword' => 'original']], source: 'edit');

        $this->restore(['batch_id' => $paste_batch_id])
            ->assertJsonPath('restored_cells', 0)
            ->assertJsonPath('message', 'Nothing to restore, the table already has these values.');
    }

    public function test_a_change_to_a_row_deleted_since_is_reported_as_skipped(): void
    {
        $placement = $this->placement();
        $this->pasteOver([$placement->id => ['keyword' => 'pasted']]);
        $paste_batch_id = $this->lastBatchId();
        $this->actingAs($this->admin, 'api')->deleteJson("/api/admin/link-building-orders/{$placement->id}")->assertOk();

        $this->restore(['batch_id' => $paste_batch_id])
            ->assertJsonPath('restored_cells', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('skipped.0.reason', 'row_deleted');
    }

    // ─── Single row changes ───────────────────────────────────────────────────

    public function test_restoring_a_single_row_change(): void
    {
        $first  = $this->placement(['keyword' => 'first original']);
        $second = $this->placement(['keyword' => 'second original']);

        $this->pasteOver([$first->id => ['keyword' => 'a'], $second->id => ['keyword' => 'b']]);

        $revision_id = LinkBuildingOrderPlacementRevision::where('placement_id', $second->id)->where('event', 'updated')->value('id');

        $this->restore(['revision_id' => $revision_id]);

        $this->assertSame('a', $first->fresh()->keyword);
        $this->assertSame('second original', $second->fresh()->keyword);
        $this->assertNull(
            LinkBuildingOrderHistoryBatch::where('batch_id', $this->lastBatchId())->value('restored_batch_id'),
            'A single row restore does not mark the whole action as restored.'
        );
    }

    public function test_restoring_an_order_id_already_used_by_another_row_is_skipped(): void
    {
        $placement = $this->placement(['order_id' => 'BL-25687']);
        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['order_id' => 'BL-25730'])
            ->assertOk();
        $rename_batch_id = $this->lastBatchId();
        $this->placement(['order_id' => 'BL-25687']);

        $this->restore(['batch_id' => $rename_batch_id])
            ->assertJsonPath('skipped.0.field', 'order_id')
            ->assertJsonPath('skipped.0.reason', 'order_id_in_use');

        $this->assertSame('BL-25730', $placement->fresh()->order_id);
    }

    // ─── Deleted rows ─────────────────────────────────────────────────────────

    public function test_a_deleted_row_is_recreated_with_its_id_and_order_id(): void
    {
        $placement = $this->placement(['order_id' => 'BL-25687', 'internal_notes' => 'keep me']);

        $this->actingAs($this->admin, 'api')->deleteJson("/api/admin/link-building-orders/{$placement->id}")->assertOk();

        $this->restore(['batch_id' => $this->lastBatchId()])
            ->assertJsonPath('recreated_rows', 1)
            ->assertJsonPath('recreated_ids.0', $placement->id);

        $restored = LinkBuildingOrderPlacement::findOrFail($placement->id);
        $this->assertSame('BL-25687', $restored->order_id);
        $this->assertSame('keep me', $restored->internal_notes);
        $this->assertTrue($restored->exact_match);
        $this->assertSame($placement->created_at->toDateTimeString(), $restored->created_at->toDateTimeString());
    }

    public function test_clear_all_can_be_restored(): void
    {
        $this->placement(['order_id' => 'BL-1']);
        $this->placement(['order_id' => 'BL-2']);

        $this->actingAs($this->admin, 'api')
            ->deleteJson('/api/admin/link-building-orders')
            ->assertOk()
            ->assertJsonPath('deleted_count', 2);

        $this->assertSame(0, LinkBuildingOrderPlacement::count());

        $this->restore(['batch_id' => $this->lastBatchId()])->assertJsonPath('recreated_rows', 2);

        $this->assertEqualsCanonicalizing(['BL-1', 'BL-2'], LinkBuildingOrderPlacement::pluck('order_id')->all());
    }

    public function test_a_deleted_row_is_not_recreated_when_its_order_id_is_taken(): void
    {
        $placement = $this->placement(['order_id' => 'BL-500']);

        $this->actingAs($this->admin, 'api')->deleteJson("/api/admin/link-building-orders/{$placement->id}")->assertOk();
        $delete_batch_id = $this->lastBatchId();

        $this->placement(['order_id' => 'BL-500']);

        $this->restore(['batch_id' => $delete_batch_id])
            ->assertJsonPath('recreated_rows', 0)
            ->assertJsonPath('skipped.0.reason', 'order_id_in_use');
    }

    public function test_restoring_rows_that_were_only_added_is_rejected(): void
    {
        $this->placement();

        $this->restore(['batch_id' => $this->lastBatchId()], 422);

        $this->assertSame(1, LinkBuildingOrderPlacement::count(), 'Restoring never deletes rows.');
    }

    // ─── Client orders ────────────────────────────────────────────────────────

    public function test_restoring_a_status_syncs_the_client_order_without_emailing_the_client(): void
    {
        $client    = $this->makeUser('client');
        $order     = $this->clientOrder($client, [['status' => 'In Progress']]);
        $placement = $order->items->first()->placements->first();

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['status' => 'Live'])
            ->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
        $status_batch_id = $this->lastBatchId();

        Mail::fake();

        $this->restore(['batch_id' => $status_batch_id]);

        $this->assertSame('In Progress', $placement->fresh()->status);
        $this->assertSame('processing', $order->fresh()->status);
        Mail::assertNothingQueued();
    }

    // ─── Broadcasting ─────────────────────────────────────────────────────────

    public function test_a_small_restore_updates_every_open_table_row_by_row(): void
    {
        $kept    = $this->placement();
        $deleted = $this->placement();
        $this->pasteOver([$kept->id => ['keyword' => 'x']]);
        $paste_batch_id = $this->lastBatchId();
        $this->actingAs($this->admin, 'api')->deleteJson("/api/admin/link-building-orders/{$deleted->id}")->assertOk();
        $delete_batch_id = $this->lastBatchId();

        Event::fake([LinkBuildingOrderUpdated::class, LinkBuildingOrderCreated::class, LinkBuildingOrdersBulkChanged::class]);

        $this->withHeader('X-Session-ID', 'tab-1')->restore(['batch_id' => $paste_batch_id]);
        $this->withHeader('X-Session-ID', 'tab-1')->restore(['batch_id' => $delete_batch_id]);

        Event::assertDispatched(LinkBuildingOrderUpdated::class, fn ($e) => $e->placement->id === $kept->id && $e->updated_by_session_id === 'tab-1');
        Event::assertDispatched(LinkBuildingOrderCreated::class, fn ($e) => $e->placement->id === $deleted->id);
        Event::assertNotDispatched(LinkBuildingOrdersBulkChanged::class);
    }

    public function test_a_large_restore_asks_open_tables_to_reload_instead_of_sending_every_row(): void
    {
        config(['link_building.history_restore_row_events_max' => 1]);
        $first  = $this->placement();
        $second = $this->placement();
        $this->pasteOver([$first->id => ['keyword' => 'x'], $second->id => ['keyword' => 'y']]);

        Event::fake([LinkBuildingOrdersBulkChanged::class, LinkBuildingOrderUpdated::class]);

        $this->restore(['batch_id' => $this->lastBatchId()])
            ->assertJsonPath('reload_required', true)
            ->assertJsonCount(0, 'data');

        Event::assertDispatched(LinkBuildingOrdersBulkChanged::class, fn ($event) => $event->row_count === 2);
        Event::assertNotDispatched(LinkBuildingOrderUpdated::class);
    }

    // ─── Requests ─────────────────────────────────────────────────────────────

    public static function invalidRestoreProvider(): array
    {
        return [
            'neither a batch nor a revision' => [[], 422],
            'an unknown conflict strategy'   => [['batch_id' => 'b', 'conflict_strategy' => 'merge'], 422],
            'a revision id that is text'     => [['revision_id' => 'abc'], 422],
            'an unknown batch'               => [['batch_id' => 'missing'], 404],
            'an unknown revision'            => [['revision_id' => 999999], 404],
        ];
    }

    #[DataProvider('invalidRestoreProvider')]
    public function test_invalid_restore_requests_are_rejected(array $body, int $status): void
    {
        $this->restore($body, $status);
    }

    public function test_staff_can_restore(): void
    {
        $placement = $this->placement(['keyword' => 'original']);
        $this->pasteOver([$placement->id => ['keyword' => 'pasted']]);

        $this->actingAs($this->makeUser('staff'), 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $this->lastBatchId()])
            ->assertOk();

        $this->assertSame('original', $placement->fresh()->keyword);
    }
}
