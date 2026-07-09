<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LinkBuildingMetricsImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super admin']);
        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        Storage::fake('local');
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'        => 'BL-' . rand(10000, 99999),
            'client'          => 'Default Client',
            'keyword'         => 'default keyword',
            'landing_page'    => 'https://example.com',
            'link_type'       => 'DR 30+ External',
            'status'          => 'New Request',
            'request_date'    => now()->format('m/d/Y'),
            'current_traffic' => 'stale-traffic',
            'dr_formula'      => 'stale-dr',
            'current_poc'     => 'stale-poc',
            'current_price'   => 'stale-price',
            'notes'           => 'do-not-touch',
        ], $overrides));
    }

    private function csvUpload(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('metrics.csv', $content);
    }

    public function test_updates_only_selected_columns_for_matched_order_id(): void
    {
        $placement = $this->placement(['order_id' => 'BL-1001']);

        $csv = "Order ID,Request Date,Current Traffic,DR Formula,Current POC,Current Price\n"
            . "BL-1001,{$placement->request_date},5000,45,poc@example.com,\$120.00\n";

        $response = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/metrics-import', [
            'file'           => $this->csvUpload($csv),
            'target_columns' => ['current_traffic', 'dr_formula'],
        ]);

        $response->assertStatus(202);
        $import_id = $response->json('import_id');

        $status = $this->actingAs($this->admin)
            ->getJson("/api/admin/link-building-orders/import-status/{$import_id}")
            ->json();

        $this->assertSame('completed', $status['status']);
        $this->assertSame(1, $status['updated']);
        $this->assertSame(0, $status['created']);

        $fresh = $placement->fresh();
        $this->assertSame('5000', $fresh->current_traffic);
        $this->assertSame('45', $fresh->dr_formula);
        // Not in target_columns — must remain untouched even though the CSV had values for them.
        $this->assertSame('stale-poc', $fresh->current_poc);
        $this->assertSame('stale-price', $fresh->current_price);
        // Completely unrelated field — must never be touched by this endpoint.
        $this->assertSame('do-not-touch', $fresh->notes);
    }

    public function test_unmatched_order_id_is_skipped_and_never_creates_a_row(): void
    {
        $csv = "Order ID,Request Date,Current Traffic\n"
            . "BL-DOES-NOT-EXIST," . now()->format('m/d/Y') . ",9999\n";

        $response = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/metrics-import', [
            'file'           => $this->csvUpload($csv),
            'target_columns' => ['current_traffic'],
        ]);

        $import_id = $response->json('import_id');
        $status = $this->actingAs($this->admin)
            ->getJson("/api/admin/link-building-orders/import-status/{$import_id}")
            ->json();

        $this->assertSame('completed', $status['status']);
        $this->assertSame(0, $status['updated']);
        $this->assertSame(1, $status['skipped']);
        $this->assertNull(LinkBuildingOrderPlacement::where('order_id', 'BL-DOES-NOT-EXIST')->first());
    }

    public function test_rows_older_than_one_year_are_skipped_by_default(): void
    {
        $placement = $this->placement(['order_id' => 'BL-2002']);
        $old_date  = now()->subYears(2)->format('m/d/Y');

        $csv = "Order ID,Request Date,Current Traffic\n"
            . "BL-2002,{$old_date},7777\n";

        $response = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/metrics-import', [
            'file'           => $this->csvUpload($csv),
            'target_columns' => ['current_traffic'],
        ]);

        $import_id = $response->json('import_id');
        $status = $this->actingAs($this->admin)
            ->getJson("/api/admin/link-building-orders/import-status/{$import_id}")
            ->json();

        $this->assertSame(0, $status['updated']);
        $this->assertSame(1, $status['skipped']);
        $this->assertSame('stale-traffic', $placement->fresh()->current_traffic);
    }

    public function test_rejects_target_columns_outside_the_allowed_list(): void
    {
        $csv = "Order ID,Notes\nBL-1,hacked\n";

        $response = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/metrics-import', [
            'file'           => $this->csvUpload($csv),
            'target_columns' => ['notes'],
        ]);

        $response->assertStatus(422);
    }

    public function test_blank_cell_clears_the_target_column_for_a_matched_row(): void
    {
        $placement = $this->placement(['order_id' => 'BL-3003']);

        $csv = "Order ID,Request Date,Current Price\n"
            . "BL-3003,{$placement->request_date},\n";

        $response = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/metrics-import', [
            'file'           => $this->csvUpload($csv),
            'target_columns' => ['current_price'],
        ]);

        $import_id = $response->json('import_id');
        $this->actingAs($this->admin)->getJson("/api/admin/link-building-orders/import-status/{$import_id}")->json();

        $this->assertNull($placement->fresh()->current_price);
    }

    public function test_non_admin_role_cannot_access_metrics_import(): void
    {
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);
        $client = User::factory()->create(['is_active' => true]);
        $client->assignRole('client');

        $csv = "Order ID,Current Traffic\nBL-1,100\n";

        $response = $this->actingAs($client)->postJson('/api/admin/link-building-orders/metrics-import', [
            'file'           => $this->csvUpload($csv),
            'target_columns' => ['current_traffic'],
        ]);

        $response->assertStatus(403);
    }
}
