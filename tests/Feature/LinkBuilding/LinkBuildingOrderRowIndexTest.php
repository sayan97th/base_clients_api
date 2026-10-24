<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * search() with include_row_index: the dashboard paints the first rows and gets
 * the ordered ids (and versions) of every matching row, then loads the rest by id
 * through rows(), see LinkBuildingOrderRowsByIdsTest.
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

    private function searchWithIndex(array $body = []): TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', array_merge(['include_row_index' => true], $body));
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_the_row_index_is_admin_only(): void
    {
        $this->postJson('/api/admin/link-building-orders/search', ['include_row_index' => true])
            ->assertStatus(401);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/admin/link-building-orders/search', ['include_row_index' => true])
            ->assertStatus(403);
    }

    // ─── Response shape ───────────────────────────────────────────────────────

    public function test_search_with_the_flag_keeps_the_paginated_shape_and_adds_the_index(): void
    {
        $this->placement();

        $this->searchWithIndex()
            ->assertOk()
            ->assertJsonStructure([
                'data', 'current_page', 'last_page', 'per_page', 'total', 'from', 'to',
                'row_index' => ['ids', 'versions'],
            ]);
    }

    public function test_search_without_the_flag_keeps_the_plain_paginated_shape(): void
    {
        $this->placement();

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertOk();

        $this->assertArrayNotHasKey('row_index', $response->json());
    }

    public function test_an_empty_result_set_has_an_empty_index(): void
    {
        $this->searchWithIndex()
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('total', 0)
            ->assertJsonPath('from', null)
            ->assertJsonPath('to', null)
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('row_index.ids', [])
            ->assertJsonPath('row_index.versions', []);
    }

    // ─── Order ────────────────────────────────────────────────────────────────

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

    public function test_the_default_order_puts_the_newest_order_id_first_numerically(): void
    {
        $nine        = $this->placement(['order_id' => 'BL-9']);
        $one_hundred = $this->placement(['order_id' => 'BL-100']);
        $ten         = $this->placement(['order_id' => 'BL-10']);

        $this->searchWithIndex()
            ->assertOk()
            ->assertJsonPath('row_index.ids', [$one_hundred->id, $ten->id, $nine->id]);
    }

    public function test_the_first_rows_are_the_first_ids_of_the_index_in_the_same_order(): void
    {
        foreach (['delta', 'alpha', 'echo', 'bravo', 'charlie'] as $keyword) {
            $this->placement(['keyword' => $keyword]);
        }

        $response = $this->searchWithIndex([
            'per_page'   => 3,
            'sort_rules' => [['key' => 'keyword', 'direction' => 'desc']],
        ])->assertOk();

        $this->assertSame(
            array_slice($response->json('row_index.ids'), 0, 3),
            array_column($response->json('data'), 'id')
        );
        $this->assertSame(['echo', 'delta', 'charlie'], array_column($response->json('data'), 'keyword'));
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
        $this->assertSame(2, $response->json('current_page'));
        $this->assertSame(3, $response->json('from'));
        $this->assertSame(3, $response->json('to'));
    }

    public function test_a_page_past_the_end_has_no_rows_but_still_the_full_index(): void
    {
        $this->placement();
        $this->placement();

        $response = $this->searchWithIndex(['page' => 5, 'per_page' => 2])->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertNull($response->json('from'));
        $this->assertCount(2, $response->json('row_index.ids'));
    }

    public function test_rows_that_tie_on_every_sort_rule_keep_a_stable_order(): void
    {
        // Ids inserted in descending order, so the insertion order the database
        // would fall back to differs from the id order the tie-breaker enforces.
        foreach (['f', 'd', 'b', 'e', 'c', 'a'] as $prefix) {
            $placement = new LinkBuildingOrderPlacement([
                'order_id'     => 'BL-' . random_int(1000, 999999),
                'client'       => 'Default Client',
                'keyword'      => 'same keyword',
                'landing_page' => 'https://example.com',
                'link_type'    => 'DR 30+ External',
                'status'       => 'New Request',
            ]);
            $placement->id = str_repeat($prefix, 8) . '-0000-4000-8000-000000000000';
            $placement->save();
        }

        $body = ['sort_rules' => [['key' => 'keyword', 'direction' => 'asc']]];

        $first  = $this->searchWithIndex($body)->json('row_index.ids');
        $second = $this->searchWithIndex($body)->json('row_index.ids');

        $sorted = $first;
        sort($sorted);

        $this->assertSame($first, $second);
        $this->assertSame($sorted, $first, 'Ties are broken by id');
    }

    public function test_plain_pages_never_repeat_or_skip_rows_that_tie_on_the_sort(): void
    {
        foreach (range(1, 7) as $n) {
            $this->placement(['status' => 'Live']);
        }

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $page_ids = array_column($this->actingAs($this->admin, 'api')
                ->postJson('/api/admin/link-building-orders/search', [
                    'page'       => $page,
                    'per_page'   => 3,
                    'sort_rules' => [['key' => 'status', 'direction' => 'asc']],
                ])
                ->json('data'), 'id');
            $seen = array_merge($seen, $page_ids);
        }

        $this->assertCount(7, $seen);
        $this->assertCount(7, array_unique($seen));
    }

    // ─── Page size ────────────────────────────────────────────────────────────

    public function test_the_page_size_is_kept_between_one_and_five_hundred(): void
    {
        $this->placement();

        $this->searchWithIndex(['per_page' => 0])
            ->assertOk()
            ->assertJsonPath('per_page', 1)
            ->assertJsonCount(1, 'data');

        $this->searchWithIndex(['per_page' => 100000])
            ->assertOk()
            ->assertJsonPath('per_page', 500);
    }

    // ─── Filters ──────────────────────────────────────────────────────────────

    public function test_search_index_follows_the_quick_filters(): void
    {
        $live = $this->placement(['status' => 'Live']);
        $this->placement(['status' => 'New Request']);

        $this->searchWithIndex(['status' => 'Live'])
            ->assertOk()
            ->assertJsonPath('row_index.ids', [$live->id])
            ->assertJsonPath('total', 1);
    }

    public function test_search_index_follows_the_global_search(): void
    {
        $match = $this->placement(['keyword' => 'best seo tools']);
        $this->placement(['keyword' => 'cheap flights']);

        $this->searchWithIndex(['search' => 'seo tools'])
            ->assertOk()
            ->assertJsonPath('row_index.ids', [$match->id]);
    }

    public function test_search_index_follows_the_column_filters(): void
    {
        $match = $this->placement(['link_type' => 'DR 50+ External']);
        $this->placement(['link_type' => 'DR 30+ External']);

        $this->searchWithIndex([
            'column_filters' => [
                ['key' => 'link_type', 'type' => 'values', 'mode' => 'include', 'values' => ['DR 50+ External']],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('row_index.ids', [$match->id]);
    }

    public function test_search_index_follows_the_advanced_filters(): void
    {
        $live    = $this->placement(['status' => 'Live']);
        $pending = $this->placement(['status' => 'Pending']);
        $this->placement(['status' => 'New Request']);

        $response = $this->searchWithIndex([
            'advanced_filters' => [
                'operator' => 'or',
                'rules'    => [
                    ['key' => 'status', 'type' => 'values', 'mode' => 'include', 'values' => ['Live']],
                    ['key' => 'status', 'type' => 'values', 'mode' => 'include', 'values' => ['Pending']],
                ],
                'groups' => [],
            ],
        ])->assertOk();

        $this->assertEqualsCanonicalizing([$live->id, $pending->id], $response->json('row_index.ids'));
    }

    public function test_the_index_only_lists_rows_shown_on_the_dashboard(): void
    {
        $shown = $this->placement();
        // Neither an admin order id, a purchase nor a client: not a dashboard row.
        LinkBuildingOrderPlacement::create(['keyword' => 'orphan', 'status' => 'New Request']);

        $this->searchWithIndex()
            ->assertOk()
            ->assertJsonPath('row_index.ids', [$shown->id]);
    }

    // ─── Versions ─────────────────────────────────────────────────────────────

    public function test_versions_are_the_unix_timestamp_of_each_row_update(): void
    {
        $placement = $this->placement();

        $this->searchWithIndex()
            ->assertOk()
            ->assertJsonPath('row_index.versions', [$placement->fresh()->updated_at->getTimestamp()]);
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
}
