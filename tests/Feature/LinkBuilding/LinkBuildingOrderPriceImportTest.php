<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prices brought in by the CSV imports of the admin "Link Building Orders" table
 * are saved formatted like the ones typed on the dashboard. Both imports write
 * with the query builder, so they format the prices themselves.
 */
class LinkBuildingOrderPriceImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        Storage::fake('local');
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . rand(10000, 99999),
            'client'       => 'Acme',
            'keyword'      => 'keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
            'request_date' => now()->format('m/d/Y'),
        ], $overrides));
    }

    private function csvUpload(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('import.csv', $content);
    }

    /** Runs an import (the queue is sync in tests) and returns its final status. */
    private function runImport(string $endpoint, array $body): array
    {
        $import_id = $this->actingAs($this->admin)
            ->postJson($endpoint, $body)
            ->assertStatus(202)
            ->json('import_id');

        return $this->actingAs($this->admin)
            ->getJson("/api/admin/link-building-orders/import-status/{$import_id}")
            ->json();
    }

    // ─── BASE link sheet import ──────────────────────────────────────────────

    public function test_the_sheet_import_formats_the_prices_of_every_row(): void
    {
        $today = now()->format('m/d/Y');
        $csv   = "Order ID,Link Type,Client,Keyword,Landing Page,Request Date,Posting Fee - LBs,Current Price,Final Price\n"
            . "BL-8001,DR 30+ External,Acme,seo,https://acme.com,{$today},\$50,1200,\"\$1,380.50\"\n"
            . "BL-8002,DR 30+ External,Acme,seo,https://acme.com,{$today},TBD,,€40\n";

        $status = $this->runImport('/api/admin/link-building-orders/import', [
            'file'              => $this->csvUpload($csv),
            'apply_date_filter' => false,
            'link_type_filter'  => 'all',
        ]);

        $this->assertSame('completed', $status['status']);
        $this->assertSame(2, $status['created']);

        $first = LinkBuildingOrderPlacement::where('order_id', 'BL-8001')->firstOrFail();
        $this->assertSame('$50.00', $first->posting_fee_lbs);
        $this->assertSame('$1,200.00', $first->current_price);
        $this->assertSame('$1,380.50', $first->final_price);

        // The sheet has no currency column: text is kept, a typed symbol is kept, a blank stays empty.
        $second = LinkBuildingOrderPlacement::where('order_id', 'BL-8002')->firstOrFail();
        $this->assertSame('TBD', $second->posting_fee_lbs);
        $this->assertNull($second->current_price);
        $this->assertSame('€40.00', $second->final_price);
    }

    // ─── Metrics import ──────────────────────────────────────────────────────

    public function test_the_metrics_import_formats_prices_with_the_currency_of_each_row(): void
    {
        $usd_row = $this->placement(['order_id' => 'BL-8101']);
        $eur_row = $this->placement(['order_id' => 'BL-8102', 'currency' => 'EUR']);

        $csv = "Order ID,Request Date,Current Price,Final Price\n"
            . "BL-8101,{$usd_row->request_date},120,\$1500\n"
            . "BL-8102,{$eur_row->request_date},\$99,TBD\n";

        $status = $this->runImport('/api/admin/link-building-orders/metrics-import', [
            'file'           => $this->csvUpload($csv),
            'target_columns' => ['current_price', 'final_price'],
        ]);

        $this->assertSame('completed', $status['status']);
        $this->assertSame(2, $status['updated']);

        $this->assertSame('$120.00', $usd_row->fresh()->current_price);
        $this->assertSame('$1,500.00', $usd_row->fresh()->final_price);
        $this->assertSame('€99.00', $eur_row->fresh()->current_price);
        $this->assertSame('TBD', $eur_row->fresh()->final_price);
    }

    public function test_the_metrics_import_leaves_columns_that_are_not_prices_unformatted(): void
    {
        $placement = $this->placement(['order_id' => 'BL-8201']);

        $csv = "Order ID,Request Date,DR LBs,Current Traffic\n"
            . "BL-8201,{$placement->request_date},55,1200\n";

        $this->runImport('/api/admin/link-building-orders/metrics-import', [
            'file'           => $this->csvUpload($csv),
            'target_columns' => ['dr_lbs', 'current_traffic'],
        ]);

        $this->assertSame('55', $placement->fresh()->dr_lbs);
        $this->assertSame('1200', $placement->fresh()->current_traffic);
    }
}
