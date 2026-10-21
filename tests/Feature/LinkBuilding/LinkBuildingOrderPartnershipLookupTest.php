<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingPartnerMetric;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every dashboard endpoint that can change a row's Partnership looks the new site
 * up in the partner metrics list, so a writer who picks a different site sees its
 * current DR, traffic, POC and price right away (or SITE REMOVED).
 */
class LinkBuildingOrderPartnershipLookupTest extends TestCase
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

        LinkBuildingPartnerMetric::create([
            'domain_key'        => 'techkaya.com',
            'domain'            => 'techkaya.com',
            'dr'                => '74',
            'traffic'           => '2300',
            'current_poc'       => 'David',
            'current_price'     => '$20',
            'metrics_synced_at' => now(),
            'partner_synced_at' => now(),
        ]);
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'        => 'BL-' . rand(10000, 99999),
            'client'          => 'Client',
            'keyword'         => 'keyword',
            'landing_page'    => 'https://example.com',
            'link_type'       => 'DR 30+ External',
            'status'          => 'New Request',
            'request_date'    => now()->format('m/d/Y'),
            'partnership'     => 'https://smiletotalk.com',
            'dr_formula'      => 'old-dr',
            'current_traffic' => 'old-traffic',
            'current_poc'     => 'old-poc',
            'current_price'   => 'old-price',
        ], $overrides));
    }

    /** Full row payload, like the one the table sends with every single cell save. */
    private function fullRowPayload(LinkBuildingOrderPlacement $placement, array $overrides = []): array
    {
        return array_merge([
            'client'          => $placement->client,
            'keyword'         => $placement->keyword,
            'landing_page'    => $placement->landing_page,
            'link_type'       => $placement->link_type,
            'status'          => $placement->status,
            'partnership'     => $placement->partnership,
            'dr_formula'      => $placement->dr_formula,
            'current_traffic' => $placement->current_traffic,
            'current_poc'     => $placement->current_poc,
            'current_price'   => $placement->current_price,
        ], $overrides);
    }

    public function test_single_row_save_fills_the_metrics_of_the_new_partnership(): void
    {
        $placement = $this->placement();

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/link-building-orders/{$placement->id}",
            $this->fullRowPayload($placement, ['partnership' => 'techkaya.com'])
        );

        $response->assertOk()
            ->assertJsonPath('data.partnership', 'https://techkaya.com')
            ->assertJsonPath('data.dr_formula', '74')
            ->assertJsonPath('data.current_traffic', '2300')
            ->assertJsonPath('data.current_poc', 'David')
            ->assertJsonPath('data.current_price', '$20');
    }

    public function test_single_row_save_of_an_unrelated_cell_keeps_manual_metrics(): void
    {
        $placement = $this->placement(['partnership' => 'https://techkaya.com', 'current_poc' => 'Manual POC']);

        $this->actingAs($this->admin)->putJson(
            "/api/admin/link-building-orders/{$placement->id}",
            $this->fullRowPayload($placement, ['keyword' => 'edited keyword'])
        )->assertOk()->assertJsonPath('data.current_poc', 'Manual POC');
    }

    public function test_single_row_save_with_a_site_outside_the_partner_list_shows_site_removed(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin)->putJson(
            "/api/admin/link-building-orders/{$placement->id}",
            $this->fullRowPayload($placement, ['partnership' => 'buddymagazine.org'])
        )->assertOk()
            ->assertJsonPath('data.current_poc', LinkBuildingPartnerMetric::SITE_REMOVED)
            ->assertJsonPath('data.current_price', LinkBuildingPartnerMetric::SITE_REMOVED)
            ->assertJsonPath('data.dr_formula', '')
            ->assertJsonPath('data.current_traffic', '');
    }

    public function test_new_row_with_a_partnership_is_created_with_its_metrics(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders', [
            'client'       => 'Client',
            'keyword'      => 'keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'partnership'  => 'https://www.techkaya.com',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.current_poc', 'David')
            ->assertJsonPath('data.dr_formula', '74');
    }

    public function test_batch_update_of_the_partnership_fills_every_selected_row(): void
    {
        $first  = $this->placement();
        $second = $this->placement(['partnership' => null]);

        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update', [
            'row_ids' => [$first->id, $second->id],
            'updates' => ['partnership' => 'techkaya.com'],
        ])->assertOk()->assertJsonPath('updated_count', 2);

        foreach ([$first, $second] as $placement) {
            $fresh = $placement->fresh();
            $this->assertSame('David', $fresh->current_poc);
            $this->assertSame('$20', $fresh->current_price);
            $this->assertSame('2300', $fresh->current_traffic);
        }
    }

    public function test_pasted_block_of_partnerships_looks_up_each_row(): void
    {
        LinkBuildingPartnerMetric::create([
            'domain_key'        => 'ecommercefastlane.com',
            'domain'            => 'ecommercefastlane.com',
            'current_poc'       => 'Muhammad',
            'current_price'     => '$80',
            'partner_synced_at' => now(),
        ]);
        $first  = $this->placement();
        $second = $this->placement();

        $response = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update-cells', [
            'updates' => [
                ['id' => $first->id,  'fields' => ['partnership' => 'techkaya.com']],
                ['id' => $second->id, 'fields' => ['partnership' => 'ecommercefastlane.com']],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.current_poc', 'David')
            ->assertJsonPath('data.1.current_poc', 'Muhammad')
            ->assertJsonPath('data.1.current_price', '$80');
    }

    public function test_cleared_partnership_keeps_the_metrics_of_the_row(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update-cells', [
            'updates' => [['id' => $placement->id, 'fields' => ['partnership' => null]]],
        ])->assertOk();

        $this->assertSame('old-poc', $placement->fresh()->current_poc);
    }

    public function test_lookup_replaces_the_formula_of_a_filled_cell(): void
    {
        $placement = $this->placement([
            'cell_formulas' => ['current_price' => '=[dr_lbs:~0]*2', 'final_price' => '=[current_price:~0]*1.15'],
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update-cells', [
            'updates' => [['id' => $placement->id, 'fields' => ['partnership' => 'techkaya.com']]],
        ]);

        $response->assertOk()->assertJsonPath('data.0.cell_formulas', ['final_price' => '=[current_price:~0]*1.15']);
    }

    public function test_rows_are_not_changed_while_the_reference_list_is_empty(): void
    {
        LinkBuildingPartnerMetric::query()->delete();
        $placement = $this->placement();

        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update-cells', [
            'updates' => [['id' => $placement->id, 'fields' => ['partnership' => 'techkaya.com']]],
        ])->assertOk();

        $fresh = $placement->fresh();
        $this->assertSame('old-poc', $fresh->current_poc);
        $this->assertSame('old-dr', $fresh->dr_formula);
    }
}
