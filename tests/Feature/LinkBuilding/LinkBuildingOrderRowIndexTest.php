<?php

namespace Tests\Feature\LinkBuilding;

use App\Http\Controllers\Admin\LinkBuilding\LinkBuildingOrdersDashboardController;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The dashboard loads every matching order into the spreadsheet: search() with
 * include_row_index paints the first rows and lists the ids of the rest, which
 * are then loaded in batches through rows().
 */
class LinkBuildingOrderRowIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . random_int(1000, 999999),
            'client'       => 'Default Client',
            'keyword'      => 'default keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
        ], $overrides));
    }

    private function searchWithIndex(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', array_merge(['include_row_index' => true], $body));
    }

    // ─── search() with include_row_index ──────────────────────────────────────

    public function test_search_lists_every_matching_row_id_in_display_order(): void
    {
        $this->placement(['order_id' => 'BL-1', 'keyword' => 'charlie']);
        $this->placement(['order_id' => 'BL-2', 'keyword' => 'alpha']);
        $this->placement(['order_id' => 'BL-3', 'keyword' => 'bravo']);

        $response = $this->searchWithIndex([
            'per_page'   => 2,
            'sort_rules' => [['key' => 'keyword', 'direction' => 'asc']],
        ])->assertOk();

        $ids_by_keyword = LinkBuildingOrderPlacement::pluck('id', 'keyword');

        $this->assertSame(
            [$ids_by_keyword['alpha'], $ids_by_keyword['bravo'], $ids_by_keyword['charlie']],
            $response->json('row_index.ids')
        );
        $this->assertCount(3, $response->json('row_index.versions'));
        $this->assertSame(3, $response->json('total'));
        $this->assertSame(2, $response->json('last_page'));
    }

    public function test_search_returns_the_requested_page_sliced_from_the_index(): void
    {
        $this->placement(['keyword' => 'alpha']);
        $this->placement(['keyword' => 'bravo']);
        $this->placement(['keyword' => 'charlie']);

        $response = $this->searchWithIndex([
            'page'       => 2,
            'per_page'   => 2,
            'sort_rules' => [['key' => 'keyword', 'direction' => 'asc']],
        ])->assertOk();

        $this->assertSame(['charlie'], array_column($response->json('data'), 'keyword'));
        $this->assertSame(3, $response->json('from'));
        $this->assertSame(3, $response->json('to'));
    }

    public function test_search_index_follows_the_active_filters(): void
    {
        $live = $this->placement(['status' => 'Live']);
        $this->placement(['status' => 'New Request']);

        $this->searchWithIndex(['status' => 'Live'])
            ->assertOk()
            ->assertJsonPath('row_index.ids', [$live->id])
            ->assertJsonPath('total', 1);
    }

    public function test_search_index_version_changes_when_a_row_is_updated(): void
    {
        $placement = $this->placement();

        $version_before = $this->searchWithIndex()->json('row_index.versions.0');

        $this->travel(5)->minutes();
        $placement->update(['keyword' => 'changed']);

        $version_after = $this->searchWithIndex()->json('row_index.versions.0');

        $this->assertGreaterThan($version_before, $version_after);
    }

    public function test_rows_that_tie_on_every_sort_rule_keep_a_stable_order(): void
    {
        foreach (range(1, 6) as $n) {
            $this->placement(['keyword' => 'same keyword']);
        }

        $body = ['sort_rules' => [['key' => 'keyword', 'direction' => 'asc']]];

        $first  = $this->searchWithIndex($body)->json('row_index.ids');
        $second = $this->searchWithIndex($body)->json('row_index.ids');

        $sorted = $first;
        sort($sorted);

        $this->assertSame($first, $second);
        $this->assertSame($sorted, $first, 'Ties are broken by id');
    }

    public function test_search_without_the_flag_keeps_the_plain_paginated_shape(): void
    {
        $this->placement();

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertOk();

        $this->assertArrayNotHasKey('row_index', $response->json());
    }

    // ─── rows() ───────────────────────────────────────────────────────────────

    public function test_rows_returns_the_rows_in_the_order_of_the_given_ids(): void
    {
        $first  = $this->placement(['order_id' => 'BL-10']);
        $second = $this->placement(['order_id' => 'BL-20']);
        $third  = $this->placement(['order_id' => 'BL-30']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/rows', ['ids' => [$third->id, $first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('data.*.order_id', ['BL-30', 'BL-10', 'BL-20']);
    }

    public function test_rows_skips_ids_that_no_longer_exist(): void
    {
        $placement = $this->placement(['order_id' => 'BL-10']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/rows', ['ids' => [(string) Str::uuid(), $placement->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', 'BL-10');
    }

    public function test_rows_returns_the_same_fields_as_search(): void
    {
        $placement = $this->placement();

        $search_row = $this->searchWithIndex()->json('data.0');
        $rows_row   = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/rows', ['ids' => [$placement->id]])
            ->json('data.0');

        $this->assertSame(array_keys($search_row), array_keys($rows_row));
    }

    public function test_rows_requires_ids(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/rows', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_rows_rejects_more_ids_than_one_batch_allows(): void
    {
        $ids = array_map(fn () => (string) Str::uuid(), range(1, LinkBuildingOrdersDashboardController::ROWS_MAX_IDS + 1));

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/rows', ['ids' => $ids])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_rows_is_admin_only(): void
    {
        $this->postJson('/api/admin/link-building-orders/rows', ['ids' => [(string) Str::uuid()]])
            ->assertStatus(401);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/admin/link-building-orders/rows', ['ids' => [(string) Str::uuid()]])
            ->assertStatus(403);
    }
}
