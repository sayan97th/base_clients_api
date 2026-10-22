<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_text_that_is_not_an_amount_is_saved_as_typed(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['current_price' => 'TBD'])
            ->assertOk()
            ->assertJsonPath('data.current_price', 'TBD');
    }
}
