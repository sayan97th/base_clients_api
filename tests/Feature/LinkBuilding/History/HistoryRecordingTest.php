<?php

namespace Tests\Feature\LinkBuilding\History;

use App\Models\LinkBuildingOrderHistoryBatch;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderPlacementRevision;
use App\Services\LinkBuildingOrderHistoryRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * Every change to the Link Building Orders table is kept in its edit history:
 * which cells changed, from what to what, who changed them and through which
 * action, whatever endpoint made the change.
 */
class HistoryRecordingTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
    }

    private function revisionsOf(LinkBuildingOrderPlacement $placement, string $event = 'updated')
    {
        return LinkBuildingOrderPlacementRevision::where('placement_id', $placement->id)->where('event', $event)->get();
    }

    // ─── Single row endpoints ─────────────────────────────────────────────────

    public function test_a_cell_edit_records_the_old_and_new_value_and_who_made_it(): void
    {
        $placement = $this->placement(['keyword' => 'old keyword']);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['keyword' => 'new keyword'])
            ->assertOk();

        $revision = $this->revisionsOf($placement)->sole();
        $this->assertSame('edit', $revision->source);
        $this->assertSame($this->admin->id, $revision->user_id);
        $this->assertSame($placement->order_id, $revision->order_id);
        $this->assertSame(['old' => 'old keyword', 'new' => 'new keyword'], $revision->cell_changes['keyword']);
    }

    public function test_an_order_id_edit_is_recorded_under_the_id_the_row_had_before(): void
    {
        $placement = $this->placement(['order_id' => 'BL-25687']);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['order_id' => 'BL-25730'])
            ->assertOk();

        $revision = $this->revisionsOf($placement)->sole();
        $this->assertSame('BL-25687', $revision->order_id);
        $this->assertSame(['old' => 'BL-25687', 'new' => 'BL-25730'], $revision->cell_changes['order_id']);
    }

    public function test_adding_a_row_records_it_whole(): void
    {
        $id = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders', [
                'link_type'    => 'DR 60+ External',
                'client'       => 'Weave',
                'keyword'      => 'dental front office training',
                'landing_page' => 'https://www.getweave.com/dental-front-office-training/',
            ])
            ->assertCreated()
            ->json('data.id');

        $revision = LinkBuildingOrderPlacementRevision::where('placement_id', $id)->sole();
        $this->assertSame('created', $revision->event);
        $this->assertSame('create', $revision->source);
        $this->assertSame('dental front office training', $revision->snapshot['keyword']);
        $this->assertSame($id, $revision->snapshot['id']);
    }

    public function test_deleting_a_row_keeps_the_whole_row_in_the_history(): void
    {
        $placement = $this->placement(['internal_notes' => 'keep me']);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/admin/link-building-orders/{$placement->id}")
            ->assertOk();

        $revision = $this->revisionsOf($placement, 'deleted')->sole();
        $this->assertSame('delete', $revision->source);
        $this->assertSame('keep me', $revision->snapshot['internal_notes']);
        $this->assertSame($placement->order_id, $revision->snapshot['order_id']);
        $this->assertNotNull($revision->snapshot['created_at']);
    }

    // ─── Batch endpoints and their change sources ─────────────────────────────

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

    public static function cellUpdateSourceProvider(): array
    {
        return [
            'undo'                    => ['undo', 'undo'],
            'redo'                    => ['redo', 'redo'],
            'clear'                   => ['clear', 'clear'],
            'fill'                    => ['fill', 'fill'],
            'no source sent'          => [null, 'paste'],
            'a server only source'    => ['restore', 'paste'],
            'an unknown source'       => ['delete_everything', 'paste'],
        ];
    }

    #[DataProvider('cellUpdateSourceProvider')]
    public function test_a_batch_cell_update_is_recorded_under_the_source_the_dashboard_sends(?string $sent, string $recorded): void
    {
        $placement = $this->placement();

        $this->pasteOver([$placement->id => ['keyword' => 'changed']], source: $sent);

        $this->assertSame($recorded, $this->revisionsOf($placement)->sole()->source);
    }

    public function test_the_bulk_fill_is_recorded_as_a_fill_unless_told_otherwise(): void
    {
        $first  = $this->placement();
        $second = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update', ['row_ids' => [$first->id], 'updates' => ['pen_name' => 'Ana']])
            ->assertOk();
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update', ['row_ids' => [$second->id], 'updates' => ['pen_name' => 'Ana'], 'change_source' => 'paste'])
            ->assertOk();

        $this->assertSame('fill', $this->revisionsOf($first)->sole()->source);
        $this->assertSame('paste', $this->revisionsOf($second)->sole()->source);
    }

    public function test_a_formula_change_is_recorded_with_the_cell_it_belongs_to(): void
    {
        $placement = $this->placement(['final_price' => '$10.00']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'change_source' => 'edit',
                'updates'       => [[
                    'id'            => $placement->id,
                    'fields'        => ['final_price' => '$20.00'],
                    'cell_formulas' => ['final_price' => '=[posting_fee_lbs:~0]*2'],
                ]],
            ])
            ->assertOk();

        $changes = $this->revisionsOf($placement)->sole()->cell_changes;
        $this->assertNull($changes['cell_formulas']['old']);
        $this->assertSame(['final_price' => '=[posting_fee_lbs:~0]*2'], $changes['cell_formulas']['new']);
    }

    public function test_values_recalculated_by_formulas_are_not_recorded(): void
    {
        $placement = $this->placement(['final_price' => '$10.00']);

        $this->pasteOver([$placement->id => ['final_price' => '$20.00']], source: 'formula');

        $this->assertSame('$20.00', $placement->fresh()->final_price);
        $this->assertCount(0, $this->revisionsOf($placement));
        $this->assertDatabaseMissing('link_building_order_history_batches', ['source' => 'formula']);
    }

    public function test_an_update_that_changes_nothing_is_not_recorded(): void
    {
        $placement = $this->placement(['keyword' => 'same']);

        $this->pasteOver([$placement->id => ['keyword' => 'same']]);

        $this->assertCount(0, $this->revisionsOf($placement));
    }

    public function test_resolving_assignments_is_recorded_as_auto_assign(): void
    {
        $placement = $this->placement(['link_builder' => 'Krista B']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/resolve-assignments')
            ->assertOk();

        $revision = $this->revisionsOf($placement)->sole();
        $this->assertSame('auto_assign', $revision->source);
        $this->assertSame($this->admin->id, $revision->cell_changes['assigned_admin_user_id']['new']);
    }

    // ─── Action summaries ─────────────────────────────────────────────────────

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
        $this->assertSame(0, $summary->created_count);
        $this->assertSame(['BL-25730', 'BL-25732'], $summary->order_ids_preview);
        $this->assertNotNull($summary->started_at);
    }

    public function test_the_summary_names_only_the_first_order_ids(): void
    {
        $updates = [];
        foreach (range(1, 8) as $i) {
            $updates[$this->placement(['order_id' => "BL-{$i}"])->id] = ['keyword' => "k{$i}"];
        }

        $this->pasteOver($updates);

        $summary = LinkBuildingOrderHistoryBatch::where('batch_id', $this->lastBatchId())->sole();
        $this->assertSame(8, $summary->rows_count);
        $this->assertCount(LinkBuildingOrderHistoryBatch::ORDER_IDS_PREVIEW_SIZE, $summary->order_ids_preview);
    }

    // ─── Writes that bypass the model ─────────────────────────────────────────

    public function test_a_bulk_write_that_bypasses_the_model_is_recorded(): void
    {
        $placement = $this->placement(['dr_lbs' => '40']);
        $recorder  = app(LinkBuildingOrderHistoryRecorder::class);
        $recorder->begin('metrics_import', 'import-123', $this->admin->id);

        $recorder->captureBulkWrite([$placement->order_id], function () use ($placement) {
            DB::table('link_building_order_placements')->where('id', $placement->id)->update(['dr_lbs' => '55']);
        });

        $revision = $this->revisionsOf($placement)->sole();
        $this->assertSame('import-123', $revision->batch_id);
        $this->assertSame('metrics_import', $revision->source);
        $this->assertSame($this->admin->id, $revision->user_id);
        $this->assertSame(['old' => '40', 'new' => '55'], $revision->cell_changes['dr_lbs']);
    }

    public function test_a_bulk_write_that_inserts_rows_records_them_as_added(): void
    {
        $recorder = app(LinkBuildingOrderHistoryRecorder::class);
        $recorder->begin('import', 'import-new', $this->admin->id);

        $recorder->captureBulkWrite(['BL-7001'], fn () => DB::table('link_building_order_placements')->insert([
            'id' => 'aaaaaaaa-0000-0000-0000-000000000001', 'order_id' => 'BL-7001', 'keyword' => 'new row',
            'exact_match' => false, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $revision = LinkBuildingOrderPlacementRevision::where('batch_id', 'import-new')->sole();
        $this->assertSame('created', $revision->event);
        $this->assertSame('new row', $revision->snapshot['keyword']);
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
}
