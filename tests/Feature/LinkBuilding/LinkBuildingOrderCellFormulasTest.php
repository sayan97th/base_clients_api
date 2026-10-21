<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spreadsheet formulas typed into cells of the admin "Link Building Orders" table:
 * the column keeps the computed value, the formula is stored in cell_formulas.
 */
class LinkBuildingOrderCellFormulasTest extends TestCase
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
            'order_id'      => 'BL-' . rand(1000, 9999),
            'client'        => 'Acme',
            'keyword'       => 'keyword',
            'landing_page'  => 'https://example.com',
            'link_type'     => 'DR 30+ External',
            'status'        => 'New Request',
            'current_price' => '100',
        ], $overrides));
    }

    public function test_update_stores_the_formula_next_to_its_computed_value(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", [
                'final_price'   => '115',
                'cell_formulas' => ['final_price' => '=[current_price:~0]*1.15'],
            ])
            ->assertOk()
            ->assertJsonPath('data.final_price', '115')
            ->assertJsonPath('data.cell_formulas.final_price', '=[current_price:~0]*1.15');

        $this->assertSame(['final_price' => '=[current_price:~0]*1.15'], $placement->fresh()->cell_formulas);
    }

    public function test_an_empty_formula_map_is_stored_as_null(): void
    {
        $placement = $this->placement(['cell_formulas' => ['final_price' => '=1']]);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['final_price' => '1', 'cell_formulas' => []])
            ->assertOk()
            ->assertJsonPath('data.cell_formulas', null);

        $this->assertNull($placement->fresh()->cell_formulas);
    }

    public function test_formulas_are_rejected_on_columns_that_cannot_hold_them(): void
    {
        $placement = $this->placement();

        // A recalculated status would send client emails, so it never accepts a formula.
        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", [
                'cell_formulas' => ['status' => '=IF(TRUE, "Live", "")'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cell_formulas']);
    }

    public function test_a_formula_must_start_with_equals(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", [
                'cell_formulas' => ['final_price' => 'SUM(1,2)'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cell_formulas.final_price']);
    }

    public function test_store_accepts_cell_formulas(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders', [
                'client'        => 'Acme',
                'keyword'       => 'keyword',
                'landing_page'  => 'https://example.com',
                'link_type'     => 'DR 30+ External',
                'dr_lbs'        => '90',
                'cell_formulas' => ['dr_lbs' => '=45*2'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.dr_lbs', '90')
            ->assertJsonPath('data.cell_formulas.dr_lbs', '=45*2');
    }

    public function test_batch_update_cells_replaces_each_rows_formulas(): void
    {
        $with_formula    = $this->placement(['cell_formulas' => ['final_price' => '=1', 'dr_lbs' => '=2']]);
        $without_formula = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [
                    // Pasting a plain value over a formula cell removes that formula.
                    ['id' => $with_formula->id, 'fields' => ['final_price' => '5'], 'cell_formulas' => ['dr_lbs' => '=2']],
                    ['id' => $without_formula->id, 'fields' => ['dr_lbs' => '60'], 'cell_formulas' => ['dr_lbs' => '=30*2']],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 2);

        $this->assertSame(['dr_lbs' => '=2'], $with_formula->fresh()->cell_formulas);
        $this->assertSame('5', $with_formula->fresh()->final_price);
        $this->assertSame(['dr_lbs' => '=30*2'], $without_formula->fresh()->cell_formulas);
    }

    public function test_batch_update_cells_can_change_only_formulas(): void
    {
        $placement = $this->placement(['cell_formulas' => ['final_price' => '=1']]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [['id' => $placement->id, 'cell_formulas' => null]],
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 1);

        $this->assertNull($placement->fresh()->cell_formulas);
    }

    public function test_batch_update_cells_validates_every_row_before_writing(): void
    {
        $first  = $this->placement(['keyword' => 'old']);
        $second = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [
                    ['id' => $first->id, 'fields' => ['keyword' => 'new']],
                    ['id' => $second->id, 'fields' => [], 'cell_formulas' => ['currency' => '="USD"']],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['updates.1.cell_formulas']);

        $this->assertSame('old', $first->fresh()->keyword, 'No row may be written when another row is invalid');
    }

    public function test_a_vlookup_formula_is_stored_exactly_as_sent(): void
    {
        $placement = $this->placement();
        $formula   = '=IFERROR(VLOOKUP([partnership:~0], [$partnership:$1]:[$current_price:$500], 11, FALSE), "New partner")';

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", [
                'current_price' => '150',
                'cell_formulas' => ['current_price' => $formula],
            ])
            ->assertOk()
            ->assertJsonPath('data.current_price', '150')
            ->assertJsonPath('data.cell_formulas.current_price', $formula);

        $this->assertSame(['current_price' => $formula], $placement->fresh()->cell_formulas);
    }

    public function test_every_formula_column_accepts_a_formula(): void
    {
        $placement = $this->placement();
        $formulas  = array_fill_keys(LinkBuildingOrderPlacement::FORMULA_COLUMNS, '=VLOOKUP("x", {"x",1}, 2, FALSE)');

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['cell_formulas' => $formulas])
            ->assertOk();

        $this->assertSame($formulas, $placement->fresh()->cell_formulas);
    }

    public function test_formulas_are_rejected_on_every_fixed_value_column(): void
    {
        $placement = $this->placement();

        foreach (['status', 'exact_match', 'currency', 'order_id', 'landing_page', 'live_link', 'link_type'] as $column) {
            $this->actingAs($this->admin, 'api')
                ->putJson("/api/admin/link-building-orders/{$placement->id}", ['cell_formulas' => [$column => '=1']])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['cell_formulas']);
        }

        $this->assertNull($placement->fresh()->cell_formulas);
    }

    public function test_a_cell_formula_longer_than_the_limit_is_rejected(): void
    {
        $placement = $this->placement();
        $limit     = LinkBuildingOrderPlacement::MAX_CELL_FORMULA_LENGTH;

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", [
                'cell_formulas' => ['notes' => '=' . str_repeat('1', $limit - 1)],
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", [
                'cell_formulas' => ['notes' => '=' . str_repeat('1', $limit)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cell_formulas.notes']);
    }

    public function test_a_lookup_result_recalculated_in_the_table_is_saved_by_the_batch_endpoint(): void
    {
        $formula   = '=VLOOKUP([order_id:~0], [order_id]:[final_price], 24, FALSE)';
        $placement = $this->placement(['final_price' => '100', 'cell_formulas' => ['current_price' => $formula]]);

        // The table recalculates the lookup after Final Price changed and saves only the new value.
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [['id' => $placement->id, 'fields' => ['current_price' => '125']]],
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 1);

        $fresh = $placement->fresh();
        $this->assertSame('125', $fresh->current_price);
        $this->assertSame(['current_price' => $formula], $fresh->cell_formulas, 'Saving a value alone keeps the formula');
    }
}
