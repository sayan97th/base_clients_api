<?php

namespace Tests\Feature\LinkBuilding;

use App\Events\LinkBuildingOrdersBulkChanged;
use App\Events\LinkBuildingOrderUpdated;
use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderHistoryBatch;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderPlacementRevision;
use App\Models\Role;
use App\Models\User;
use App\Services\LinkBuildingOrderHistoryRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Edit history of the Link Building Orders table: every change is recorded per
 * action and can be restored, so rows pasted over by mistake can be put back.
 */
class LinkBuildingOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true, 'first_name' => 'Krista', 'last_name' => 'B']);
        $this->admin->assignRole('admin');
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . fake()->unique()->numberBetween(1000, 99999),
            'client'       => 'Weave',
            'keyword'      => 'other fatigue icd 10',
            'landing_page' => 'https://www.getweave.com/fatigue-icd-10/',
            'link_type'    => 'DR 60+ External',
            'exact_match'  => true,
            'status'       => 'New Request',
        ], $overrides));
    }

    private function pasteOver(array $rows, string $source = 'paste'): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'change_source' => $source,
                'updates'       => array_map(fn ($id, $fields) => ['id' => $id, 'fields' => $fields], array_keys($rows), $rows),
            ])
            ->assertOk();
    }

    private function lastBatchId(): string
    {
        return LinkBuildingOrderPlacementRevision::orderByDesc('id')->value('batch_id');
    }

    // ─── Recording ────────────────────────────────────────────────────────────

    public function test_a_cell_edit_records_the_old_and_new_value_and_who_made_it(): void
    {
        $placement = $this->placement(['keyword' => 'old keyword']);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['keyword' => 'new keyword'])
            ->assertOk();

        $revision = LinkBuildingOrderPlacementRevision::where('placement_id', $placement->id)
            ->where('event', 'updated')
            ->sole();

        $this->assertSame('edit', $revision->source);
        $this->assertSame($this->admin->id, $revision->user_id);
        $this->assertSame($placement->order_id, $revision->order_id);
        $this->assertSame(['old' => 'old keyword', 'new' => 'new keyword'], $revision->cell_changes['keyword']);
    }

    public function test_one_paste_over_many_rows_is_one_history_entry(): void
    {
        $first  = $this->placement();
        $second = $this->placement();

        $this->pasteOver([
            $first->id  => ['client' => 'Mather', 'keyword' => 'senior living'],
            $second->id => ['client' => 'Mather', 'keyword' => 'retirement community'],
        ]);

        $revisions = LinkBuildingOrderPlacementRevision::where('event', 'updated')->get();

        $this->assertCount(2, $revisions);
        $this->assertCount(1, $revisions->pluck('batch_id')->unique());
        $this->assertSame(['paste'], $revisions->pluck('source')->unique()->values()->all());
    }

    public function test_an_unknown_change_source_falls_back_to_the_endpoint_default(): void
    {
        $placement = $this->placement();

        $this->pasteOver([$placement->id => ['keyword' => 'x']], source: 'restore');

        $this->assertSame('paste', LinkBuildingOrderPlacementRevision::where('event', 'updated')->value('source'));
    }

    public function test_an_update_that_changes_nothing_is_not_recorded(): void
    {
        $placement = $this->placement(['keyword' => 'same']);

        $this->pasteOver([$placement->id => ['keyword' => 'same']]);

        $this->assertDatabaseMissing('link_building_order_placement_revisions', ['event' => 'updated']);
    }

    public function test_a_bulk_write_that_bypasses_the_model_is_recorded(): void
    {
        $placement = $this->placement(['dr_lbs' => '40']);
        $recorder  = app(LinkBuildingOrderHistoryRecorder::class);
        $recorder->begin('metrics_import', 'import-123', $this->admin->id);

        $recorder->captureBulkWrite([$placement->order_id], function () use ($placement) {
            DB::table('link_building_order_placements')->where('id', $placement->id)->update(['dr_lbs' => '55']);
        });

        $revision = LinkBuildingOrderPlacementRevision::where('event', 'updated')->sole();
        $this->assertSame('import-123', $revision->batch_id);
        $this->assertSame('metrics_import', $revision->source);
        $this->assertSame(['old' => '40', 'new' => '55'], $revision->cell_changes['dr_lbs']);
    }

    // ─── Restoring ────────────────────────────────────────────────────────────

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

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $paste_batch_id])
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('restored_cells', 8)
            ->assertJsonCount(2, 'data');

        $first->refresh();
        $second->refresh();
        $this->assertSame('Weave', $first->client);
        $this->assertSame('other fatigue icd 10', $first->keyword);
        $this->assertSame('https://www.getweave.com/fatigue-icd-10/', $first->landing_page);
        $this->assertTrue($first->exact_match);
        $this->assertSame('https://www.getweave.com/dental-front-office-training/', $second->landing_page);
        $this->assertFalse($second->exact_match);

        // The restore is itself in the history, linked to the paste it restored.
        $this->assertSame(2, LinkBuildingOrderPlacementRevision::where('source', 'restore')->where('restored_batch_id', $paste_batch_id)->count());

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/history?source=paste')
            ->assertOk()
            ->assertJsonPath('data.0.batch_id', $paste_batch_id)
            ->assertJsonPath('data.0.rows_count', 2)
            ->assertJsonPath('data.0.user.name', 'Krista B')
            ->assertJsonPath('data.0.cells_count', 8)
            ->assertJsonPath('data.0.restored.user.id', $this->admin->id)
            ->assertJsonPath('data.0.restored.restored_at', fn (string $at) => str_ends_with($at, '+00:00'));
    }

    public function test_restore_stops_when_a_restored_cell_was_edited_again(): void
    {
        $placement = $this->placement(['keyword' => 'original', 'client' => 'Weave']);

        $this->pasteOver([$placement->id => ['keyword' => 'pasted', 'client' => 'Mather']]);
        $paste_batch_id = $this->lastBatchId();

        // Someone fixes the keyword by hand afterwards.
        $this->pasteOver([$placement->id => ['keyword' => 'fixed by hand']], source: 'edit');

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $paste_batch_id])
            ->assertStatus(409)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('conflicts.0.field', 'keyword')
            ->assertJsonPath('conflicts.0.current_value', 'fixed by hand')
            ->assertJsonPath('conflicts.0.restore_value', 'original');

        $this->assertSame('Mather', $placement->fresh()->client, 'Nothing is restored while there are conflicts.');

        // Skipping the conflicts restores the other cells only.
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $paste_batch_id, 'conflict_strategy' => 'skip'])
            ->assertOk()
            ->assertJsonPath('restored_cells', 1);

        $this->assertSame('Weave', $placement->fresh()->client);
        $this->assertSame('fixed by hand', $placement->fresh()->keyword);

        // Overwriting puts the keyword back as well.
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $paste_batch_id, 'conflict_strategy' => 'overwrite'])
            ->assertOk();

        $this->assertSame('original', $placement->fresh()->keyword);
    }

    public function test_restoring_a_single_row_change(): void
    {
        $first  = $this->placement(['keyword' => 'first original']);
        $second = $this->placement(['keyword' => 'second original']);

        $this->pasteOver([$first->id => ['keyword' => 'a'], $second->id => ['keyword' => 'b']]);

        $revision_id = LinkBuildingOrderPlacementRevision::where('placement_id', $second->id)->where('event', 'updated')->value('id');

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['revision_id' => $revision_id])
            ->assertOk();

        $this->assertSame('a', $first->fresh()->keyword);
        $this->assertSame('second original', $second->fresh()->keyword);
    }

    public function test_a_deleted_row_is_recreated_with_its_id_and_order_id(): void
    {
        $placement = $this->placement(['order_id' => 'BL-25687', 'internal_notes' => 'keep me']);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/admin/link-building-orders/{$placement->id}")
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $this->lastBatchId()])
            ->assertOk()
            ->assertJsonPath('recreated_rows', 1)
            ->assertJsonPath('recreated_ids.0', $placement->id);

        $restored = LinkBuildingOrderPlacement::findOrFail($placement->id);
        $this->assertSame('BL-25687', $restored->order_id);
        $this->assertSame('keep me', $restored->internal_notes);
        $this->assertTrue($restored->exact_match);
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

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $this->lastBatchId()])
            ->assertOk()
            ->assertJsonPath('recreated_rows', 2);

        $this->assertEqualsCanonicalizing(['BL-1', 'BL-2'], LinkBuildingOrderPlacement::pluck('order_id')->all());
    }

    public function test_a_deleted_row_is_not_recreated_when_its_order_id_is_taken(): void
    {
        $placement = $this->placement(['order_id' => 'BL-500']);

        $this->actingAs($this->admin, 'api')->deleteJson("/api/admin/link-building-orders/{$placement->id}")->assertOk();
        $delete_batch_id = $this->lastBatchId();

        $this->placement(['order_id' => 'BL-500']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $delete_batch_id])
            ->assertOk()
            ->assertJsonPath('recreated_rows', 0)
            ->assertJsonPath('skipped.0.reason', 'order_id_in_use');
    }

    public function test_restoring_rows_that_were_only_added_is_rejected(): void
    {
        $this->placement();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $this->lastBatchId()])
            ->assertStatus(422);
    }

    public function test_restoring_a_status_syncs_the_client_order_without_emailing_the_client(): void
    {
        $client = User::factory()->create(['is_active' => true]);
        DrTier::create([
            'id' => 'dr60', 'label' => 'DR 60+', 'min_dr' => 60, 'max_dr' => 100, 'traffic_range' => '10k+',
            'word_count' => 800, 'price_per_link' => 300.0, 'is_hidden' => false, 'is_active' => true,
        ]);
        $order = LinkBuildingOrder::create(['user_id' => $client->id, 'total_amount' => 300, 'status' => 'processing']);
        $item  = $order->items()->create(['dr_tier_id' => 'dr60', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300]);
        $placement = $item->placements()->create(['order_id' => 'BL-9', 'row_index' => 0, 'status' => 'In Progress']);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['status' => 'Live'])
            ->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
        $status_batch_id = $this->lastBatchId();

        Mail::fake();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $status_batch_id])
            ->assertOk();

        $this->assertSame('In Progress', $placement->fresh()->status);
        $this->assertSame('processing', $order->fresh()->status);
        Mail::assertNothingQueued();
    }

    // ─── Reading ──────────────────────────────────────────────────────────────

    public function test_row_history_lists_the_changes_of_one_row_newest_first(): void
    {
        $placement = $this->placement(['keyword' => 'v1']);
        $this->pasteOver([$placement->id => ['keyword' => 'v2']]);
        $this->pasteOver([$placement->id => ['keyword' => 'v3']]);

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/link-building-orders/{$placement->id}/history")
            ->assertOk()
            ->assertJsonPath('order_id', $placement->order_id)
            ->assertJsonPath('is_deleted', false)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.changes.0.old', 'v2')
            ->assertJsonPath('data.0.changes.0.new', 'v3')
            ->assertJsonPath('data.2.event', 'created');
    }

    public function test_clients_cannot_read_or_restore_the_history(): void
    {
        $client = User::factory()->create(['is_active' => true]);
        $client->assignRole('client');

        $this->actingAs($client, 'api')->getJson('/api/admin/link-building-orders/history')->assertForbidden();
        $this->actingAs($client, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => 'x'])
            ->assertForbidden();
    }

    // ─── Scale ────────────────────────────────────────────────────────────────

    public function test_each_action_keeps_a_summary_so_the_list_never_aggregates_revisions(): void
    {
        $first  = $this->placement(['order_id' => 'BL-25730']);
        $second = $this->placement(['order_id' => 'BL-25732']);

        $this->pasteOver([
            $first->id  => ['client' => 'Mather', 'keyword' => 'a'],
            $second->id => ['client' => 'Mather'],
        ]);

        $summary = LinkBuildingOrderHistoryBatch::where('batch_id', $this->lastBatchId())->sole();
        $this->assertSame('paste', $summary->source);
        $this->assertSame($this->admin->id, $summary->user_id);
        $this->assertSame(2, $summary->revisions_count);
        $this->assertSame(2, $summary->rows_count);
        $this->assertSame(3, $summary->cells_count);
        $this->assertSame(2, $summary->updated_count);
        $this->assertSame(['BL-25730', 'BL-25732'], $summary->order_ids_preview);
    }

    public function test_a_bulk_write_adds_to_the_summary_of_its_import(): void
    {
        $placement = $this->placement(['dr_lbs' => '40']);
        $recorder  = app(LinkBuildingOrderHistoryRecorder::class);
        $recorder->begin('import', 'import-7', $this->admin->id);

        foreach (['50', '60'] as $value) {
            $recorder->captureBulkWrite([$placement->order_id], fn () => DB::table('link_building_order_placements')
                ->where('id', $placement->id)
                ->update(['dr_lbs' => $value]));
        }

        $summary = LinkBuildingOrderHistoryBatch::where('batch_id', 'import-7')->sole();
        $this->assertSame(2, $summary->revisions_count);
        $this->assertSame(1, $summary->rows_count, 'A row changed by two chunks of one import counts once.');
    }

    public function test_the_list_pages_with_a_cursor(): void
    {
        $placement = $this->placement();
        foreach (['a', 'b', 'c'] as $keyword) {
            $this->pasteOver([$placement->id => ['keyword' => $keyword]]);
        }

        $first_page = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/history?source=paste&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/history?source=paste&per_page=2&before_id=' . $first_page->json('meta.next_before_id'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_before_id', null);
    }

    public function test_the_list_finds_actions_by_the_start_of_an_order_id(): void
    {
        $weave  = $this->placement(['order_id' => 'BL-25730']);
        $mather = $this->placement(['order_id' => 'BL-31000']);
        $this->pasteOver([$weave->id => ['keyword' => 'x']]);
        $this->pasteOver([$mather->id => ['keyword' => 'y']]);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/history?source=paste&order_id=2573')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_ids_preview.0', 'BL-25730');
    }

    public function test_the_revisions_of_a_large_action_load_a_page_at_a_time(): void
    {
        $updates = [];
        foreach (range(1, 3) as $i) {
            $updates[$this->placement()->id] = ['keyword' => "pasted {$i}"];
        }
        $this->pasteOver($updates);

        $first_page = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/history/batches/' . $this->lastBatchId() . '?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/history/batches/' . $this->lastBatchId() . '?per_page=2&after_id=' . $first_page->json('meta.next_after_id'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.changes.0.new', 'pasted 3');
    }

    public function test_values_recalculated_by_formulas_are_not_recorded(): void
    {
        $placement = $this->placement(['final_price' => '$10.00']);

        $this->pasteOver([$placement->id => ['final_price' => '$20.00']], source: 'formula');

        $this->assertSame('$20.00', $placement->fresh()->final_price);
        $this->assertDatabaseMissing('link_building_order_placement_revisions', ['event' => 'updated']);
        $this->assertDatabaseMissing('link_building_order_history_batches', ['source' => 'formula']);
    }

    public function test_a_large_action_is_restored_on_the_queue(): void
    {
        config(['link_building.history_sync_restore_max_revisions' => 1]);
        $first  = $this->placement(['keyword' => 'one']);
        $second = $this->placement(['keyword' => 'two']);
        $this->pasteOver([$first->id => ['keyword' => 'x'], $second->id => ['keyword' => 'y']]);

        $restore_id = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $this->lastBatchId()])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->json('restore_id');

        // The test queue runs jobs right away.
        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/link-building-orders/history/restores/{$restore_id}")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.applied', true)
            ->assertJsonPath('result.restored_cells', 2);

        $this->assertSame('one', $first->fresh()->keyword);
        $this->assertSame('two', $second->fresh()->keyword);
    }

    public function test_only_the_admin_who_started_a_queued_restore_can_read_it(): void
    {
        config(['link_building.history_sync_restore_max_revisions' => 0]);
        $placement = $this->placement();
        $this->pasteOver([$placement->id => ['keyword' => 'x']]);

        $restore_id = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $this->lastBatchId()])
            ->json('restore_id');

        $other_admin = User::factory()->create(['is_active' => true]);
        $other_admin->assignRole('admin');

        $this->actingAs($other_admin, 'api')
            ->getJson("/api/admin/link-building-orders/history/restores/{$restore_id}")
            ->assertNotFound();
    }

    public function test_a_large_restore_asks_open_tables_to_reload_instead_of_sending_every_row(): void
    {
        config(['link_building.history_restore_row_events_max' => 1]);
        $first  = $this->placement();
        $second = $this->placement();
        $this->pasteOver([$first->id => ['keyword' => 'x'], $second->id => ['keyword' => 'y']]);

        Event::fake([LinkBuildingOrdersBulkChanged::class, LinkBuildingOrderUpdated::class]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', ['batch_id' => $this->lastBatchId()])
            ->assertOk()
            ->assertJsonPath('reload_required', true)
            ->assertJsonCount(0, 'data');

        Event::assertDispatched(LinkBuildingOrdersBulkChanged::class, fn ($event) => $event->row_count === 2);
        Event::assertNotDispatched(LinkBuildingOrderUpdated::class);
    }
}
