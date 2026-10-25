<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Default order of the admin "Link Building Orders" table: without a sort of
 * their own, the search, the row index, find-pages and the CSV export all list
 * the newest orders first (highest BL number first).
 */
class LinkBuildingOrderDefaultSortTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH_URL = '/api/admin/link-building-orders/search';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'client'       => 'Default Client',
            'keyword'      => 'default keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
        ], $overrides));
    }

    /** Creates one placement per order id, in the given (deliberately unordered) sequence. */
    private function placements(array $order_ids): void
    {
        foreach ($order_ids as $order_id) {
            $this->placement(['order_id' => $order_id]);
        }
    }

    /** Order ids of the search response, in the order the API returned them. */
    private function searchOrderIds(array $body = []): array
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson(self::SEARCH_URL, $body)
            ->assertStatus(200);

        return array_column($response->json('data'), 'order_id');
    }

    // ─── Search ───────────────────────────────────────────────────────────────

    public function test_search_lists_the_newest_orders_first_without_a_sort(): void
    {
        // Created out of order, and BL-25009 must outrank BL-9999 numerically.
        $this->placements(['BL-9999', 'BL-25009', 'BL-100']);

        $this->assertSame(['BL-25009', 'BL-9999', 'BL-100'], $this->searchOrderIds());
    }

    public function test_search_lists_the_newest_orders_first_with_an_empty_sort(): void
    {
        $this->placements(['BL-2', 'BL-3', 'BL-1']);

        $this->assertSame(['BL-3', 'BL-2', 'BL-1'], $this->searchOrderIds(['sort_rules' => []]));
    }

    public function test_search_follows_the_default_sort_the_table_sends(): void
    {
        $this->placements(['BL-2', 'BL-3', 'BL-1']);

        $order_ids = $this->searchOrderIds([
            'sort_rules' => [['key' => 'order_id', 'direction' => 'desc', 'nulls_last' => true]],
        ]);

        $this->assertSame(['BL-3', 'BL-2', 'BL-1'], $order_ids);
    }

    public function test_search_lists_the_oldest_orders_first_when_asked(): void
    {
        $this->placements(['BL-9999', 'BL-25009', 'BL-100']);

        $order_ids = $this->searchOrderIds([
            'sort_rules' => [['key' => 'order_id', 'direction' => 'asc']],
        ]);

        $this->assertSame(['BL-100', 'BL-9999', 'BL-25009'], $order_ids);
    }

    public function test_search_follows_another_column_when_the_user_sorts_by_it(): void
    {
        $this->placement(['order_id' => 'BL-3', 'client' => 'Beta Corp']);
        $this->placement(['order_id' => 'BL-1', 'client' => 'Zeta Corp']);
        $this->placement(['order_id' => 'BL-2', 'client' => 'Acme Corp']);

        $order_ids = $this->searchOrderIds([
            'sort_rules' => [['key' => 'client', 'direction' => 'asc']],
        ]);

        $this->assertSame(['BL-2', 'BL-3', 'BL-1'], $order_ids);
    }

    public function test_search_keeps_rows_that_tie_on_the_sort_in_a_stable_order(): void
    {
        // Same status everywhere, so the rows tie on the only rule. The order must stay
        // stable between requests, so the same rows never move from page to page.
        $this->placements(['BL-1', 'BL-2', 'BL-3']);
        $body = ['sort_rules' => [['key' => 'status', 'direction' => 'asc']]];

        $this->assertSame($this->searchOrderIds($body), $this->searchOrderIds($body));
    }

    /**
     * Rules the API can not apply are skipped, so they must never leave the table
     * without an order: it falls back to the newest orders first.
     */
    public static function unusableSortRulesProvider(): array
    {
        return [
            'unknown column'       => [[['key' => 'not_a_column', 'direction' => 'asc']]],
            'computed column'      => [[['key' => 'days_left', 'direction' => 'asc']]],
            'invalid direction'    => [[['key' => 'client', 'direction' => 'sideways']]],
            'sql in the key'       => [[['key' => 'client; DROP TABLE users', 'direction' => 'asc']]],
            'rule without a key'   => [[['direction' => 'asc']]],
        ];
    }

    #[DataProvider('unusableSortRulesProvider')]
    public function test_search_falls_back_to_the_newest_orders_first_for_unusable_rules(array $sort_rules): void
    {
        $this->placements(['BL-2', 'BL-3', 'BL-1']);

        $this->assertSame(['BL-3', 'BL-2', 'BL-1'], $this->searchOrderIds(['sort_rules' => $sort_rules]));
    }

    public function test_search_puts_the_newest_orders_on_the_first_page(): void
    {
        $this->placements(['BL-1', 'BL-5', 'BL-3', 'BL-2', 'BL-4']);

        $this->assertSame(['BL-5', 'BL-4'], $this->searchOrderIds(['per_page' => 2, 'page' => 1]));
        $this->assertSame(['BL-3', 'BL-2'], $this->searchOrderIds(['per_page' => 2, 'page' => 2]));
        $this->assertSame(['BL-1'], $this->searchOrderIds(['per_page' => 2, 'page' => 3]));
    }

    public function test_search_keeps_the_newest_orders_first_while_filtering(): void
    {
        $this->placement(['order_id' => 'BL-1', 'status' => 'Live']);
        $this->placement(['order_id' => 'BL-3', 'status' => 'Live']);
        $this->placement(['order_id' => 'BL-2', 'status' => 'Writing']);

        $this->assertSame(['BL-3', 'BL-1'], $this->searchOrderIds(['status' => 'Live']));
    }

    public function test_search_lists_legacy_placements_without_an_order_id_after_the_numbered_ones(): void
    {
        // Rows without an order id pre-date sequential ids, so they are the oldest ones.
        $this->placements(['BL-1', 'BL-2']);
        $this->placement(['order_id' => null, 'user_id' => $this->admin->id]);

        $order_ids = $this->searchOrderIds();

        $this->assertSame(['BL-2', 'BL-1'], array_slice($order_ids, 0, 2));
        $this->assertCount(3, $order_ids);
    }

    public function test_a_newly_created_order_shows_up_first(): void
    {
        $this->placements(['BL-10', 'BL-11']);

        $created_order_id = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders', [
                'link_type'    => 'DR 30+ External',
                'client'       => 'Acme Corp',
                'keyword'      => 'seo agency',
                'landing_page' => 'https://acme.com',
                'status'       => 'New Request',
                'exact_match'  => 'No',
                'currency'     => 'USD',
            ])
            ->assertStatus(201)
            ->json('data.order_id');

        $this->assertSame('BL-12', $created_order_id);
        $this->assertSame(['BL-12', 'BL-11', 'BL-10'], $this->searchOrderIds());
    }

    // ─── Row index, find-pages and export ─────────────────────────────────────

    public function test_row_index_lists_the_newest_orders_first(): void
    {
        $this->placements(['BL-2', 'BL-3', 'BL-1']);
        $ids_by_order_id = LinkBuildingOrderPlacement::pluck('id', 'order_id');

        $response = $this->actingAs($this->admin, 'api')
            ->postJson(self::SEARCH_URL, ['include_row_index' => true, 'per_page' => 2])
            ->assertStatus(200);

        $this->assertSame(
            [$ids_by_order_id['BL-3'], $ids_by_order_id['BL-2'], $ids_by_order_id['BL-1']],
            $response->json('row_index.ids')
        );
        $this->assertSame(['BL-3', 'BL-2'], array_column($response->json('data'), 'order_id'));
    }

    public function test_find_pages_counts_pages_from_the_newest_orders(): void
    {
        $this->placements(['BL-1', 'BL-2', 'BL-3']);

        // Newest first: page 1 = BL-3, page 2 = BL-2, page 3 = BL-1.
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/find-pages', [
                'find_terms' => ['BL-3'],
                'per_page'   => 1,
            ])
            ->assertStatus(200);

        $this->assertSame([['page' => 1, 'row_count' => 1]], $response->json('pages'));
    }

    public function test_export_lists_the_newest_orders_first_without_a_sort(): void
    {
        $this->placements(['BL-9999', 'BL-25009', 'BL-100']);

        $csv = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/export')
            ->assertStatus(200)
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $order_ids = array_map(fn (string $line) => str_getcsv($line)[0], array_slice($lines, 1));

        $this->assertSame(['BL-25009', 'BL-9999', 'BL-100'], $order_ids);
    }
}
