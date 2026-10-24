<?php

namespace Tests\Feature\LinkBuilding;

use App\Http\Controllers\Admin\LinkBuilding\LinkBuildingOrdersDashboardController;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /api/admin/link-building-orders/rows: the batches the dashboard loads by id
 * after the row index, until every matching row is in the spreadsheet.
 */
class LinkBuildingOrderRowsByIdsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 23));

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->client = User::factory()->create(['is_active' => true, 'company' => 'Globex Inc']);
        $this->client->assignRole('client');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    private function rows(array $ids): TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/rows', ['ids' => $ids]);
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_rows_is_admin_only(): void
    {
        $this->postJson('/api/admin/link-building-orders/rows', ['ids' => [(string) Str::uuid()]])
            ->assertStatus(401);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/admin/link-building-orders/rows', ['ids' => [(string) Str::uuid()]])
            ->assertStatus(403);
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    public function test_rows_requires_ids(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/rows', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_rows_rejects_an_empty_list(): void
    {
        $this->rows([])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_rows_rejects_ids_that_are_not_strings(): void
    {
        $this->rows([['nested']])->assertStatus(422)->assertJsonValidationErrors('ids.0');
    }

    public function test_rows_rejects_ids_longer_than_a_uuid(): void
    {
        $this->rows([str_repeat('a', 37)])->assertStatus(422)->assertJsonValidationErrors('ids.0');
    }

    public function test_rows_accepts_a_full_batch(): void
    {
        $ids = array_map(fn () => (string) Str::uuid(), range(1, LinkBuildingOrdersDashboardController::ROWS_MAX_IDS));

        $this->rows($ids)->assertOk()->assertJsonPath('data', []);
    }

    public function test_rows_rejects_more_ids_than_one_batch_allows(): void
    {
        $ids = array_map(fn () => (string) Str::uuid(), range(1, LinkBuildingOrdersDashboardController::ROWS_MAX_IDS + 1));

        $this->rows($ids)->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    // ─── Content ──────────────────────────────────────────────────────────────

    public function test_rows_returns_the_rows_in_the_order_of_the_given_ids(): void
    {
        $first  = $this->placement(['order_id' => 'BL-10']);
        $second = $this->placement(['order_id' => 'BL-20']);
        $third  = $this->placement(['order_id' => 'BL-30']);

        $this->rows([$third->id, $first->id, $second->id])
            ->assertOk()
            ->assertJsonPath('data.*.order_id', ['BL-30', 'BL-10', 'BL-20']);
    }

    public function test_rows_returns_a_repeated_id_once(): void
    {
        $placement = $this->placement(['order_id' => 'BL-10']);

        $this->rows([$placement->id, $placement->id])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_rows_skips_ids_that_no_longer_exist(): void
    {
        $placement = $this->placement(['order_id' => 'BL-10']);

        $this->rows([(string) Str::uuid(), $placement->id])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', 'BL-10');
    }

    public function test_rows_skips_a_row_deleted_after_the_index_was_built(): void
    {
        $kept    = $this->placement(['order_id' => 'BL-10']);
        $deleted = $this->placement(['order_id' => 'BL-20']);

        $ids = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', ['include_row_index' => true])
            ->json('row_index.ids');

        $deleted->delete();

        $this->rows($ids)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$kept->id]);
    }

    public function test_rows_never_returns_rows_hidden_from_the_dashboard(): void
    {
        $orphan = LinkBuildingOrderPlacement::create(['keyword' => 'orphan', 'status' => 'New Request']);

        $this->rows([$orphan->id])->assertOk()->assertJsonPath('data', []);
    }

    public function test_rows_returns_the_same_fields_and_values_as_search(): void
    {
        $placement = $this->placement();

        $search_row = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', ['include_row_index' => true])
            ->json('data.0');
        $rows_row = $this->rows([$placement->id])->json('data.0');

        $this->assertSame($search_row, $rows_row);
    }

    public function test_rows_derives_the_company_from_the_assigned_client_account(): void
    {
        $placement = $this->placement(['client' => null, 'user_id' => $this->client->id]);

        $this->rows([$placement->id])
            ->assertOk()
            ->assertJsonPath('data.0.client', 'Globex Inc');
    }

    public function test_rows_includes_the_assigned_team_member(): void
    {
        $member = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $placement = $this->placement(['assigned_admin_user_id' => $member->id]);

        $this->rows([$placement->id])
            ->assertOk()
            ->assertJsonPath('data.0.assigned_admin_user_name', 'Jane Doe');
    }

    public function test_rows_flags_a_domain_recently_used_for_the_same_page(): void
    {
        $this->placement([
            'order_id'     => 'BL-100',
            'partnership'  => 'https://techkaya.com',
            'landing_page' => 'https://client.com/services',
            'request_date' => '08/15/2026',
            'status'       => 'Live',
        ]);
        $current = $this->placement([
            'order_id'     => 'BL-200',
            'partnership'  => 'https://techkaya.com',
            'landing_page' => 'https://client.com/services',
            'request_date' => '10/20/2026',
        ]);

        // Only the current row is in the batch, the earlier use is still found.
        $this->rows([$current->id])
            ->assertOk()
            ->assertJsonPath('data.0.recent_domain_usage.count', 1)
            ->assertJsonPath('data.0.recent_domain_usage.last_order_id', 'BL-100');
    }

    // ─── Performance ──────────────────────────────────────────────────────────

    public function test_the_number_of_queries_does_not_grow_with_the_batch_size(): void
    {
        $makeRows = function (int $count): array {
            return array_map(function () {
                $member = User::factory()->create();
                $client = User::factory()->create(['company' => 'Client ' . Str::random(4)]);

                return $this->placement([
                    'client'                 => null,
                    'user_id'                => $client->id,
                    'assigned_admin_user_id' => $member->id,
                    'partnership'            => 'https://site-' . Str::random(6) . '.com',
                ])->id;
            }, range(1, $count));
        };

        $small_batch = $makeRows(2);
        $large_batch = $makeRows(15);

        $countQueries = function (array $ids): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->rows($ids)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // A first request warms up what the auth guard caches.
        $countQueries($small_batch);

        $this->assertSame($countQueries($small_batch), $countQueries($large_batch));
    }
}
