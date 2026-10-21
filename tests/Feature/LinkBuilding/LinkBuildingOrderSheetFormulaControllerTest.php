<?php

namespace Tests\Feature\LinkBuilding;

use App\Events\LinkBuildingOrderSheetFormulasChanged;
use App\Models\LinkBuildingOrderSheetFormula;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LinkBuildingOrderSheetFormulaControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = '/api/admin/link-building-orders/sheet-formulas';

    private User $admin;
    private User $staff;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super admin']);
        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'staff'],       ['display_name' => 'Staff',       'description' => 'Staff user']);
        Role::firstOrCreate(['name' => 'client'],      ['display_name' => 'Client',      'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true, 'first_name' => 'Ana', 'last_name' => 'Admin']);
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create(['is_active' => true]);
        $this->staff->assignRole('staff');

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');

        Event::fake([LinkBuildingOrderSheetFormulasChanged::class]);
    }

    private function column(array $overrides = []): LinkBuildingOrderSheetFormula
    {
        return LinkBuildingOrderSheetFormula::create(array_merge([
            'kind'     => LinkBuildingOrderSheetFormula::KIND_COLUMN,
            'name'     => 'Margin',
            'formula'  => '=[final_price:~0]-[current_price:~0]',
            'format'   => 'currency',
            'position' => 1,
        ], $overrides));
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_unauthenticated_index_returns_401(): void
    {
        $this->getJson(self::BASE_URL)->assertStatus(401);
    }

    public function test_client_role_cannot_read_or_change_formulas(): void
    {
        $this->actingAs($this->client, 'api')->getJson(self::BASE_URL)->assertStatus(403);
        $this->actingAs($this->client, 'api')
            ->postJson(self::BASE_URL . '/columns', ['name' => 'X', 'formula' => '=1'])
            ->assertStatus(403);

        $this->assertDatabaseCount('link_building_order_sheet_formulas', 0);
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function test_index_returns_columns_in_position_order_and_summary_cells(): void
    {
        $second = $this->column(['name' => 'Second', 'position' => 2]);
        $first  = $this->column(['name' => 'First', 'position' => 1]);
        LinkBuildingOrderSheetFormula::create([
            'kind'       => LinkBuildingOrderSheetFormula::KIND_SUMMARY,
            'target_key' => 'final_price',
            'formula'    => '=SUM([final_price]:[final_price])',
        ]);

        $this->actingAs($this->staff, 'api')
            ->getJson(self::BASE_URL)
            ->assertOk()
            ->assertJsonPath('data.columns.0.id', $first->id)
            ->assertJsonPath('data.columns.0.key', 'formula_' . $first->id)
            ->assertJsonPath('data.columns.1.id', $second->id)
            ->assertJsonPath('data.summary.0.key', 'final_price')
            ->assertJsonPath('data.summary.0.formula', '=SUM([final_price]:[final_price])');
    }

    // ─── Columns ──────────────────────────────────────────────────────────────

    public function test_admin_can_add_a_formula_column_at_the_end(): void
    {
        $this->column(['position' => 4]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . '/columns', [
                'name'    => '  Days left  ',
                'formula' => '=[estimated_delivery_date:~0]-TODAY()',
                'format'  => 'number',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Days left')
            ->assertJsonPath('data.kind', 'column')
            ->assertJsonPath('data.position', 5)
            ->assertJsonPath('data.updated_by_name', 'Ana Admin');

        $this->assertSame('formula_' . $response->json('data.id'), $response->json('data.key'));
        Event::assertDispatched(LinkBuildingOrderSheetFormulasChanged::class);
    }

    public function test_store_rejects_a_formula_without_equals_sign_and_an_unknown_format(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . '/columns', ['name' => 'Bad', 'formula' => 'SUM(1,2)', 'format' => 'emoji'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['formula', 'format']);
    }

    public function test_store_requires_a_name(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . '/columns', ['formula' => '=1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_enforces_the_column_limit(): void
    {
        for ($i = 0; $i < LinkBuildingOrderSheetFormula::MAX_COLUMNS; $i++) {
            $this->column(['name' => "Column {$i}", 'position' => $i]);
        }

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . '/columns', ['name' => 'One too many', 'formula' => '=1'])
            ->assertStatus(422);
    }

    public function test_admin_can_update_a_formula_column(): void
    {
        $column = $this->column();

        $this->actingAs($this->staff, 'api')
            ->putJson(self::BASE_URL . "/columns/{$column->id}", ['formula' => '=[final_price:~0]*2', 'format' => 'auto'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Margin')
            ->assertJsonPath('data.formula', '=[final_price:~0]*2')
            ->assertJsonPath('data.format', 'auto');

        $this->assertSame($this->staff->id, $column->fresh()->updated_by_user_id);
    }

    public function test_update_cannot_target_a_summary_cell(): void
    {
        $summary = LinkBuildingOrderSheetFormula::create([
            'kind'       => LinkBuildingOrderSheetFormula::KIND_SUMMARY,
            'target_key' => 'dr_lbs',
            'formula'    => '=AVERAGE([dr_lbs]:[dr_lbs])',
        ]);

        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . "/columns/{$summary->id}", ['name' => 'Hijack'])
            ->assertStatus(404);
    }

    public function test_deleting_a_column_also_clears_its_totals_cell(): void
    {
        $column = $this->column();
        LinkBuildingOrderSheetFormula::create([
            'kind'       => LinkBuildingOrderSheetFormula::KIND_SUMMARY,
            'target_key' => $column->columnKey(),
            'formula'    => '=SUM([' . $column->columnKey() . ']:[' . $column->columnKey() . '])',
        ]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson(self::BASE_URL . "/columns/{$column->id}")
            ->assertOk();

        $this->assertDatabaseCount('link_building_order_sheet_formulas', 0);
        Event::assertDispatched(LinkBuildingOrderSheetFormulasChanged::class);
    }

    // ─── Totals row ───────────────────────────────────────────────────────────

    public function test_summary_cell_is_created_updated_and_cleared(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . '/summary', ['target_key' => 'final_price', 'formula' => '=SUM([final_price]:[final_price])'])
            ->assertOk()
            ->assertJsonPath('data.summary.0.formula', '=SUM([final_price]:[final_price])');

        $this->actingAs($this->staff, 'api')
            ->putJson(self::BASE_URL . '/summary', ['target_key' => 'final_price', 'formula' => '=MAX([final_price]:[final_price])'])
            ->assertOk()
            ->assertJsonCount(1, 'data.summary')
            ->assertJsonPath('data.summary.0.formula', '=MAX([final_price]:[final_price])');

        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . '/summary', ['target_key' => 'final_price', 'formula' => '  '])
            ->assertOk()
            ->assertJsonCount(0, 'data.summary');

        $this->assertDatabaseCount('link_building_order_sheet_formulas', 0);
    }

    public function test_summary_rejects_an_invalid_target_key(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . '/summary', ['target_key' => 'final-price; DROP', 'formula' => '=1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_key']);
    }

    public function test_summary_cell_can_sit_under_a_formula_column(): void
    {
        $column = $this->column();

        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . '/summary', [
                'target_key' => $column->columnKey(),
                'formula'    => '=SUM([' . $column->columnKey() . ']:[' . $column->columnKey() . '])',
            ])
            ->assertOk()
            ->assertJsonPath('data.summary.0.key', $column->columnKey())
            ->assertJsonPath('data.columns.0.id', $column->id);
    }

    public function test_summary_rejects_a_formula_without_equals_sign(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . '/summary', ['target_key' => 'final_price', 'formula' => 'SUM(1,2)'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['formula'])
            ->assertJsonPath('errors.formula.0', 'A formula must start with "=".');

        $this->assertDatabaseCount('link_building_order_sheet_formulas', 0);
    }

    // ─── Lookup formulas ──────────────────────────────────────────────────────

    public function test_a_vlookup_formula_is_stored_and_returned_exactly_as_sent(): void
    {
        // Quotes, commas, "$" anchors, braces and non ASCII text must survive the round trip untouched.
        $formula = '=IFERROR(VLOOKUP([link_type:~0], {"DA 30+ External",30;"Express Link",7}, 2, FALSE)'
            . ' + VLOOKUP([partnership:~0], [$partnership:$1]:[$current_price:$500], 11, FALSE), "Verify Capacity w/ Teams, ñ é")';

        $response = $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . '/columns', ['name' => 'Partner price', 'formula' => $formula])
            ->assertStatus(201)
            ->assertJsonPath('data.formula', $formula);

        $this->assertSame($formula, LinkBuildingOrderSheetFormula::find($response->json('data.id'))->formula);

        $this->actingAs($this->staff, 'api')
            ->getJson(self::BASE_URL)
            ->assertOk()
            ->assertJsonPath('data.columns.0.formula', $formula);
    }

    public function test_store_accepts_a_formula_at_the_length_limit_and_rejects_a_longer_one(): void
    {
        $limit = LinkBuildingOrderSheetFormula::MAX_FORMULA_LENGTH;

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . '/columns', ['name' => 'Long', 'formula' => '=' . str_repeat('1', $limit - 1)])
            ->assertStatus(201);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . '/columns', ['name' => 'Too long', 'formula' => '=' . str_repeat('1', $limit)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['formula']);
    }

    // ─── Column details ───────────────────────────────────────────────────────

    public function test_store_uses_the_given_position_and_defaults_the_format_to_auto(): void
    {
        $this->column(['position' => 1]);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . '/columns', ['name' => 'First', 'formula' => '=1', 'position' => 0])
            ->assertStatus(201)
            ->assertJsonPath('data.position', 0)
            ->assertJsonPath('data.format', 'auto');

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL)
            ->assertJsonPath('data.columns.0.name', 'First')
            ->assertJsonPath('data.columns.1.name', 'Margin');
    }

    public function test_store_rejects_a_name_or_position_out_of_bounds(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . '/columns', [
                'name'     => str_repeat('a', 101),
                'formula'  => '=1',
                'position' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'position']);
    }

    public function test_update_rejects_an_empty_name_and_a_formula_without_equals_sign(): void
    {
        $column = $this->column();

        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . "/columns/{$column->id}", ['name' => '   ', 'formula' => 'VLOOKUP(1, A:B, 2)'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'formula']);

        $this->assertSame('Margin', $column->fresh()->name);
    }

    public function test_update_and_delete_return_404_for_an_unknown_column(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . '/columns/999999', ['name' => 'Ghost'])
            ->assertStatus(404);

        $this->actingAs($this->admin, 'api')
            ->deleteJson(self::BASE_URL . '/columns/999999')
            ->assertStatus(404);

        Event::assertNotDispatched(LinkBuildingOrderSheetFormulasChanged::class);
    }

    public function test_deleting_a_column_keeps_the_other_totals_cells(): void
    {
        $column = $this->column();
        LinkBuildingOrderSheetFormula::create([
            'kind'       => LinkBuildingOrderSheetFormula::KIND_SUMMARY,
            'target_key' => 'final_price',
            'formula'    => '=SUM([final_price]:[final_price])',
        ]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson(self::BASE_URL . "/columns/{$column->id}")
            ->assertOk();

        $this->assertDatabaseHas('link_building_order_sheet_formulas', ['target_key' => 'final_price']);
    }

    // ─── Authorization and broadcasting ───────────────────────────────────────

    public function test_unauthenticated_requests_cannot_change_formulas(): void
    {
        $column = $this->column();

        $this->postJson(self::BASE_URL . '/columns', ['name' => 'X', 'formula' => '=1'])->assertStatus(401);
        $this->putJson(self::BASE_URL . "/columns/{$column->id}", ['name' => 'X'])->assertStatus(401);
        $this->deleteJson(self::BASE_URL . "/columns/{$column->id}")->assertStatus(401);
        $this->putJson(self::BASE_URL . '/summary', ['target_key' => 'dr_lbs', 'formula' => '=1'])->assertStatus(401);

        $this->assertDatabaseCount('link_building_order_sheet_formulas', 1);
    }

    public function test_every_change_is_broadcast_with_the_session_of_the_editor(): void
    {
        $headers = ['X-Session-ID' => 'session-abc'];

        $created = $this->actingAs($this->admin, 'api')
            ->withHeaders($headers)
            ->postJson(self::BASE_URL . '/columns', ['name' => 'Days', 'formula' => '=1'])
            ->assertStatus(201);
        $id = $created->json('data.id');

        $this->withHeaders($headers)->putJson(self::BASE_URL . "/columns/{$id}", ['name' => 'Days left'])->assertOk();
        $this->withHeaders($headers)
            ->putJson(self::BASE_URL . '/summary', ['target_key' => 'dr_lbs', 'formula' => '=1'])
            ->assertOk();
        $this->withHeaders($headers)->deleteJson(self::BASE_URL . "/columns/{$id}")->assertOk();

        Event::assertDispatchedTimes(LinkBuildingOrderSheetFormulasChanged::class, 4);
        Event::assertDispatched(
            LinkBuildingOrderSheetFormulasChanged::class,
            fn (LinkBuildingOrderSheetFormulasChanged $event) => $event->changed_by_session_id === 'session-abc'
        );
    }

    public function test_reading_formulas_is_not_broadcast(): void
    {
        $this->actingAs($this->admin, 'api')->getJson(self::BASE_URL)->assertOk();

        Event::assertNotDispatched(LinkBuildingOrderSheetFormulasChanged::class);
    }
}
