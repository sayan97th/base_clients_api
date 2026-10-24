<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use App\Services\LinkBuildingDomainUsageService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A row's Partnership is flagged (and highlighted in red on the dashboard) when
 * the same domain was already used for the same Landing Page in the three months
 * before the row's Request Date.
 */
class LinkBuildingOrderRecentDomainUsageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 23));

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super admin']);
        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . rand(10000, 99999),
            'client'       => 'Client',
            'keyword'      => 'keyword',
            'landing_page' => 'https://client.com/services',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
            'request_date' => '10/20/2026',
            'partnership'  => 'https://techkaya.com',
        ], $overrides));
    }

    /** @return Collection<string, array|null> recent_domain_usage of every row, by row id */
    private function searchUsages(): Collection
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/link-building-orders/search', ['per_page' => 100])
            ->assertOk();

        return collect($response->json('data'))->pluck('recent_domain_usage', 'id');
    }

    public function test_keys_are_normalized_on_save(): void
    {
        $placement = $this->placement([
            'partnership'  => 'HTTPS://www.TechKaya.com/blog/',
            'landing_page' => 'https://www.client.com/services/?utm_source=x#top',
        ]);

        $this->assertSame('techkaya.com', $placement->partnership_key);
        $this->assertSame('client.com/services', $placement->landing_page_key);

        $placement->update(['partnership' => null]);

        $this->assertNull($placement->fresh()->partnership_key);
    }

    public function test_domain_used_for_the_same_page_in_the_last_three_months_is_flagged(): void
    {
        $earlier = $this->placement(['order_id' => 'BL-100', 'request_date' => '08/15/2026', 'status' => 'Live']);
        $current = $this->placement([
            'order_id'     => 'BL-200',
            'partnership'  => 'www.techkaya.com',
            'landing_page' => 'client.com/services/',
        ]);

        $usages = $this->searchUsages();

        $this->assertSame([
            'count'             => 1,
            'last_order_id'     => 'BL-100',
            'last_request_date' => '08/15/2026',
            'window_months'     => LinkBuildingDomainUsageService::WINDOW_MONTHS,
        ], $usages[$current->id]);

        // Only the later order is flagged, the earlier one had no use before it.
        $this->assertNull($usages[$earlier->id]);
    }

    public function test_use_older_than_three_months_is_not_flagged(): void
    {
        $this->placement(['request_date' => '07/19/2026', 'status' => 'Live']);
        $current = $this->placement();

        $this->assertNull($this->searchUsages()[$current->id]);
    }

    public function test_same_domain_for_a_different_page_is_not_flagged(): void
    {
        $this->placement(['request_date' => '09/01/2026', 'landing_page' => 'https://client.com/about']);
        $current = $this->placement();

        $this->assertNull($this->searchUsages()[$current->id]);
    }

    public function test_cancelled_orders_do_not_count_as_a_use(): void
    {
        $this->placement(['request_date' => '09/01/2026', 'status' => 'Cancelled']);
        $current = $this->placement();

        $this->assertNull($this->searchUsages()[$current->id]);
    }

    public function test_the_most_recent_use_and_the_total_are_reported(): void
    {
        $this->placement(['order_id' => 'BL-1', 'request_date' => '08/01/2026']);
        $this->placement(['order_id' => 'BL-2', 'request_date' => '09/10/2026']);
        $current = $this->placement(['order_id' => 'BL-3']);

        $usage = $this->searchUsages()[$current->id];

        $this->assertSame(2, $usage['count']);
        $this->assertSame('BL-2', $usage['last_order_id']);
        $this->assertSame('09/10/2026', $usage['last_request_date']);
    }

    public function test_changing_the_partnership_returns_the_flag_with_the_saved_row(): void
    {
        $this->placement(['order_id' => 'BL-100', 'request_date' => '09/01/2026']);
        $current = $this->placement(['partnership' => 'https://another-site.com']);

        $this->actingAs($this->admin)->putJson("/api/admin/link-building-orders/{$current->id}", [
            'client'       => $current->client,
            'keyword'      => $current->keyword,
            'landing_page' => $current->landing_page,
            'link_type'    => $current->link_type,
            'status'       => $current->status,
            'request_date' => $current->request_date,
            'partnership'  => 'techkaya.com',
        ])->assertOk()
            ->assertJsonPath('data.recent_domain_usage.last_order_id', 'BL-100');
    }

    public function test_cell_and_batch_updates_return_the_flag(): void
    {
        $this->placement(['order_id' => 'BL-100', 'request_date' => '09/01/2026']);
        $first  = $this->placement(['partnership' => 'https://another-site.com']);
        $second = $this->placement(['partnership' => 'https://another-site.com']);

        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update-cells', [
            'updates' => [['id' => $first->id, 'fields' => ['partnership' => 'techkaya.com']]],
        ])->assertOk()->assertJsonPath('data.0.recent_domain_usage.last_order_id', 'BL-100');

        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update', [
            'row_ids' => [$second->id],
            'updates' => ['partnership' => 'techkaya.com'],
        ])->assertOk()->assertJsonPath('data.0.recent_domain_usage.count', 2);
    }

    public function test_the_sheet_import_sets_the_keys_of_imported_rows(): void
    {
        Storage::fake('local');

        $csv = "Order ID,Link Type,Client,Keyword,Landing Page,Request Date,Partnership\n"
            . "BL-8001,DR 30+ External,Acme,seo,https://www.acme.com/page/,10/01/2026,https://www.techkaya.com\n";

        $import_id = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/import', [
            'file'              => UploadedFile::fake()->createWithContent('import.csv', $csv),
            'apply_date_filter' => false,
            'link_type_filter'  => 'all',
        ])->assertStatus(202)->json('import_id');

        $this->actingAs($this->admin)
            ->getJson("/api/admin/link-building-orders/import-status/{$import_id}")
            ->assertJsonPath('status', 'completed');

        $imported = LinkBuildingOrderPlacement::where('order_id', 'BL-8001')->firstOrFail();
        $this->assertSame('techkaya.com', $imported->partnership_key);
        $this->assertSame('acme.com/page', $imported->landing_page_key);
    }

    public function test_migration_backfills_the_keys_of_existing_rows(): void
    {
        $placement = $this->placement(['partnership' => 'https://www.techkaya.com/']);
        LinkBuildingOrderPlacement::whereKey($placement->id)->toBase()->update([
            'partnership_key'  => null,
            'landing_page_key' => null,
        ]);

        $migration = require database_path('migrations/2026_10_23_000001_add_usage_keys_to_link_building_order_placements_table.php');
        $migration->down();
        $migration->up();

        $fresh = $placement->fresh();
        $this->assertSame('techkaya.com', $fresh->partnership_key);
        $this->assertSame('client.com/services', $fresh->landing_page_key);
    }
}
