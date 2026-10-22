<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prices entered on the admin "Link Building Orders" table get the currency
 * symbol of their row automatically, whichever way they are saved.
 */
class LinkBuildingOrderPriceFormatTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . rand(1000, 9999),
            'client'       => 'Acme',
            'keyword'      => 'keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
        ], $overrides));
    }

    public function test_a_new_row_saves_its_prices_with_the_dollar_sign(): void
    {
        $placement = $this->placement(['posting_fee_lbs' => '50', 'current_price' => '1200', 'final_price' => '1380.5']);

        $this->assertSame('$50.00', $placement->posting_fee_lbs);
        $this->assertSame('$1,200.00', $placement->current_price);
        $this->assertSame('$1,380.50', $placement->final_price);
    }

    public function test_updating_a_price_cell_adds_the_dollar_sign(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['final_price' => '250'])
            ->assertOk()
            ->assertJsonPath('data.final_price', '$250.00');
    }

    public function test_pasted_prices_get_the_dollar_sign(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [['id' => $placement->id, 'fields' => ['posting_fee_lbs' => '40', 'current_price' => '1,000']]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.posting_fee_lbs', '$40.00')
            ->assertJsonPath('data.0.current_price', '$1,000.00');
    }

    public function test_a_euro_row_uses_the_euro_sign(): void
    {
        $placement = $this->placement(['currency' => 'EUR', 'final_price' => '99']);

        $this->assertSame('€99.00', $placement->final_price);
    }

    public function test_changing_the_currency_updates_the_symbol_of_every_price(): void
    {
        $placement = $this->placement(['posting_fee_lbs' => '50', 'final_price' => '120']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update', [
                'row_ids' => [$placement->id],
                'updates' => ['currency' => 'EUR'],
            ])
            ->assertOk();

        $fresh = $placement->fresh();
        $this->assertSame('€50.00', $fresh->posting_fee_lbs);
        $this->assertSame('€120.00', $fresh->final_price);
    }

    public function test_creating_a_row_from_the_dashboard_formats_its_prices(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders', [
                'link_type'       => 'DR 30+ External',
                'client'          => 'Acme',
                'keyword'         => 'seo tools',
                'landing_page'    => 'https://acme.com',
                'posting_fee_lbs' => '45',
                'current_price'   => '1500',
                'final_price'     => '$1725.5',
            ])
            ->assertCreated()
            ->assertJsonPath('data.posting_fee_lbs', '$45.00')
            ->assertJsonPath('data.current_price', '$1,500.00')
            ->assertJsonPath('data.final_price', '$1,725.50');
    }

    public function test_switching_the_currency_from_the_row_editor_updates_every_price(): void
    {
        $placement = $this->placement(['posting_fee_lbs' => '50', 'current_price' => '100', 'final_price' => '120']);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['currency' => 'EUR'])
            ->assertOk()
            ->assertJsonPath('data.posting_fee_lbs', '€50.00')
            ->assertJsonPath('data.current_price', '€100.00')
            ->assertJsonPath('data.final_price', '€120.00');

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['currency' => 'USD'])
            ->assertOk()
            ->assertJsonPath('data.final_price', '$120.00');
    }

    public function test_a_price_and_a_new_currency_sent_together_use_the_new_currency(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['final_price' => '$80', 'currency' => 'EUR'])
            ->assertOk()
            ->assertJsonPath('data.final_price', '€80.00');
    }

    public function test_bulk_filling_a_price_column_formats_every_row(): void
    {
        $first  = $this->placement();
        $second = $this->placement(['currency' => 'EUR']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update', [
                'row_ids' => [$first->id, $second->id],
                'updates' => ['final_price' => '75'],
            ])
            ->assertOk();

        $this->assertSame('$75.00', $first->fresh()->final_price);
        $this->assertSame('€75.00', $second->fresh()->final_price);
    }

    public function test_a_formula_result_in_a_price_cell_is_saved_formatted(): void
    {
        $placement = $this->placement(['current_price' => '100']);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", [
                'final_price'   => '115',
                'cell_formulas' => ['final_price' => '=[current_price:~0]*1.15'],
            ])
            ->assertOk()
            ->assertJsonPath('data.final_price', '$115.00')
            ->assertJsonPath('data.cell_formulas.final_price', '=[current_price:~0]*1.15');
    }

    public function test_clearing_a_price_cell_leaves_it_empty(): void
    {
        $placement = $this->placement(['final_price' => '120']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [['id' => $placement->id, 'fields' => ['final_price' => null]]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.final_price', '');

        $this->assertNull($placement->fresh()->final_price);
    }

    public function test_editing_another_column_keeps_the_saved_prices_as_they_are(): void
    {
        $placement = $this->placement();
        // A price saved before prices were formatted, the data migration formats those.
        DB::table('link_building_order_placements')->where('id', $placement->id)->update(['final_price' => '150']);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['keyword' => 'new keyword'])
            ->assertOk()
            ->assertJsonPath('data.final_price', '150');
    }

    public function test_search_returns_the_formatted_prices(): void
    {
        $this->placement(['order_id' => 'BL-7001', 'final_price' => '2500']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertOk();

        $this->assertSame('$2,500.00', collect($response->json('data'))->firstWhere('order_id', 'BL-7001')['final_price']);
    }

    public function test_number_conditions_still_compare_formatted_prices_as_numbers(): void
    {
        $this->placement(['order_id' => 'BL-7101', 'final_price' => '90']);
        $this->placement(['order_id' => 'BL-7102', 'final_price' => '1250']);
        $this->placement(['order_id' => 'BL-7103', 'final_price' => '300', 'currency' => 'EUR']);

        $order_ids = fn (string $operator, string $value, string $value_to = '') => collect(
            $this->actingAs($this->admin, 'api')
                ->postJson('/api/admin/link-building-orders/search', [
                    'column_filters' => [[
                        'key'      => 'final_price',
                        'type'     => 'condition',
                        'operator' => $operator,
                        'value'    => $value,
                        'value_to' => $value_to,
                    ]],
                ])
                ->assertOk()
                ->json('data')
        )->pluck('order_id')->sort()->values()->all();

        $this->assertSame(['BL-7102', 'BL-7103'], $order_ids('greater_than', '100'));
        $this->assertSame(['BL-7102'], $order_ids('greater_than_or_equal', '1250'));
        $this->assertSame(['BL-7101', 'BL-7103'], $order_ids('is_between', '50', '500'));
    }

    public function test_sorting_by_a_price_column_orders_by_amount(): void
    {
        $this->placement(['order_id' => 'BL-7201', 'final_price' => '90']);
        $this->placement(['order_id' => 'BL-7202', 'final_price' => '1250']);
        $this->placement(['order_id' => 'BL-7203', 'final_price' => '300']);

        $prices = fn (string $direction) => array_column(
            $this->actingAs($this->admin, 'api')
                ->postJson('/api/admin/link-building-orders/search', [
                    'sort_rules' => [['key' => 'final_price', 'direction' => $direction]],
                ])
                ->assertOk()
                ->json('data'),
            'final_price'
        );

        $this->assertSame(['$90.00', '$300.00', '$1,250.00'], $prices('asc'));
        $this->assertSame(['$1,250.00', '$300.00', '$90.00'], $prices('desc'));
    }

    public function test_text_that_is_not_an_amount_is_saved_as_typed(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['current_price' => 'TBD'])
            ->assertOk()
            ->assertJsonPath('data.current_price', 'TBD');
    }
}
