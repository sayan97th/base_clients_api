<?php

namespace Tests\Feature\LinkBuilding\History;

use App\Models\LinkBuildingOrderHistoryBatch;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderPlacementRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * Writes that bypass Eloquent (the CSV imports and the "clear all" endpoint and
 * command) still land in the edit history, so a wrong import or an accidental
 * clear can be restored like any other change.
 */
class HistoryBulkWritesTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
    }

    /** Runs an import (the queue is sync in tests) and returns its import id. */
    private function runImport(string $endpoint, array $body): string
    {
        return $this->actingAs($this->admin, 'api')
            ->postJson($endpoint, $body)
            ->assertStatus(202)
            ->json('import_id');
    }

    public function test_a_sheet_import_records_what_it_changed_and_added_under_its_import_id(): void
    {
        $today    = now()->format('m/d/Y');
        $existing = $this->placement(['order_id' => 'BL-8001', 'keyword' => 'before import', 'request_date' => $today]);

        $import_id = $this->runImport('/api/admin/link-building-orders/import', [
            'file' => UploadedFile::fake()->createWithContent('import.csv',
                "Order ID,Link Type,Client,Keyword,Landing Page,Request Date\n"
                . "BL-8001,DR 60+ External,Weave,from import,https://www.getweave.com/a,{$today}\n"
                . "BL-8002,DR 60+ External,Weave,brand new,https://www.getweave.com/b,{$today}\n"),
            'apply_date_filter' => false,
            'link_type_filter'  => 'all',
        ]);

        $revisions = LinkBuildingOrderPlacementRevision::where('batch_id', $import_id)->get();
        $this->assertSame(['import'], $revisions->pluck('source')->unique()->values()->all());
        $this->assertSame([$this->admin->id], $revisions->pluck('user_id')->unique()->values()->all());

        $update = $revisions->firstWhere('placement_id', $existing->id);
        $this->assertSame('updated', $update->event);
        $this->assertSame(['old' => 'before import', 'new' => 'from import'], $update->cell_changes['keyword']);

        $created = $revisions->firstWhere('event', 'created');
        $this->assertSame('BL-8002', $created->order_id);

        $summary = LinkBuildingOrderHistoryBatch::where('batch_id', $import_id)->sole();
        $this->assertSame(1, $summary->updated_count);
        $this->assertSame(1, $summary->created_count);
    }

    public function test_a_wrong_import_can_be_restored(): void
    {
        $today    = now()->format('m/d/Y');
        $existing = $this->placement(['order_id' => 'BL-8001', 'keyword' => 'before import', 'request_date' => $today]);

        $import_id = $this->runImport('/api/admin/link-building-orders/import', [
            'file' => UploadedFile::fake()->createWithContent('import.csv',
                "Order ID,Keyword,Request Date\nBL-8001,wrong keyword,{$today}\n"),
            'apply_date_filter' => false,
            'link_type_filter'  => 'all',
        ]);
        $this->assertSame('wrong keyword', $existing->fresh()->keyword);

        $this->restore(['batch_id' => $import_id])->assertJsonPath('applied', true);

        $this->assertSame('before import', $existing->fresh()->keyword);
    }

    public function test_a_metrics_update_records_the_values_it_replaced(): void
    {
        $placement = $this->placement(['order_id' => 'BL-1001', 'current_traffic' => '100', 'request_date' => now()->format('m/d/Y')]);

        $import_id = $this->runImport('/api/admin/link-building-orders/metrics-import', [
            'file'           => UploadedFile::fake()->createWithContent('metrics.csv',
                "Order ID,Request Date,Current Traffic\nBL-1001,{$placement->request_date},5000\n"),
            'target_columns' => ['current_traffic'],
        ]);

        $revision = LinkBuildingOrderPlacementRevision::where('batch_id', $import_id)->sole();
        $this->assertSame('metrics_import', $revision->source);
        $this->assertSame($this->admin->id, $revision->user_id);
        $this->assertSame(['old' => '100', 'new' => '5000'], $revision->cell_changes['current_traffic']);
    }

    public function test_clear_all_keeps_every_deleted_row_in_the_history(): void
    {
        $this->placement(['order_id' => 'BL-1']);
        $this->placement(['order_id' => 'BL-2']);

        $this->actingAs($this->admin, 'api')->deleteJson('/api/admin/link-building-orders')->assertOk();

        $revisions = LinkBuildingOrderPlacementRevision::where('event', 'deleted')->get();
        $this->assertCount(2, $revisions);
        $this->assertSame(['clear_all'], $revisions->pluck('source')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['BL-1', 'BL-2'], $revisions->pluck('snapshot.order_id')->all());
        $this->assertSame(2, LinkBuildingOrderHistoryBatch::where('source', 'clear_all')->sole()->deleted_count);
    }

    public function test_the_clear_dashboard_command_keeps_every_deleted_row_in_the_history(): void
    {
        $this->placement(['order_id' => 'BL-1']);
        $client_row = $this->clientOrder($this->makeUser('client'))->items->first()->placements->first();

        $this->artisan('link-building:clear-dashboard', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, LinkBuildingOrderPlacement::count());
        $revisions = LinkBuildingOrderPlacementRevision::where('event', 'deleted')->get();
        $this->assertCount(2, $revisions);
        $this->assertSame(['clear_all'], $revisions->pluck('source')->unique()->values()->all());

        $this->restore(['batch_id' => $revisions->first()->batch_id])->assertJsonPath('recreated_rows', 2);
        $this->assertTrue(LinkBuildingOrderPlacement::whereKey($client_row->id)->exists());
    }
}
