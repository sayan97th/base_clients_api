<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API contract behind the spreadsheet keyboard shortcuts of the admin "Link Building
 * Orders" table (Google Sheets style, Cmd on Mac and Ctrl elsewhere):
 *  - Cmd+; inserts today's date as MM/DD/YYYY and saves it through the single row update;
 *  - Cmd+X clears the cut cells and Cmd+D / Cmd+R fill cells, both through batch-update-cells;
 *  - filled formulas keep their row relative references, so every row stores the same formula;
 *  - locked columns (Request Date, Delivery Date) are never overwritten by a fill.
 */
class LinkBuildingOrderKeyboardShortcutsTest extends TestCase
{
    use RefreshDatabase;

    private const BATCH_CELLS_URL = '/api/admin/link-building-orders/batch-update-cells';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

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
            'order_id'     => 'BL-' . rand(1000, 9999),
            'client'       => 'Acme',
            'keyword'      => 'keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
        ], $overrides));
    }

    private function batchUpdateCells(array $updates)
    {
        return $this->actingAs($this->admin, 'api')->postJson(self::BATCH_CELLS_URL, ['updates' => $updates]);
    }

    // ─── Cmd+; (insert today's date) ──────────────────────────────────────────

    public static function editableDateColumns(): array
    {
        return [
            'Live Link Date' => ['live_link_date'],
            'Approval Date'  => ['approval_date'],
            'Request Date'   => ['request_date'],
            'Delivery Date'  => ['estimated_delivery_date'],
        ];
    }

    #[DataProvider('editableDateColumns')]
    public function test_update_saves_the_inserted_date_in_a_date_column(string $column): void
    {
        $placement = $this->placement();
        $today     = Carbon::today()->format('m/d/Y');

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", [$column => $today])
            ->assertOk()
            ->assertJsonPath("data.{$column}", $today);

        $this->assertSame($today, $placement->fresh()->{$column});
    }

    public function test_update_saves_a_date_inserted_inside_a_text_cell(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['notes' => 'Followed up on 10/21/2026'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Followed up on 10/21/2026');
    }

    public function test_an_inserted_delivery_date_of_today_is_not_overdue(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 21, 9));
        $placement = $this->placement();

        $response = $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['estimated_delivery_date' => '10/21/2026'])
            ->assertOk();

        $this->assertSame('10/21/2026', $response->json('data.estimated_delivery_date'));
        $this->assertSame(0, (int) $response->json('data.days_left'));
    }

    public function test_a_date_inserted_with_the_shortcut_matches_the_date_filters(): void
    {
        $this->placement(['order_id' => 'BL-7001', 'live_link_date' => '10/21/2026']);
        $this->placement(['order_id' => 'BL-7002', 'live_link_date' => '10/20/2026']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', [
                'column_filters' => [[
                    'key'      => 'live_link_date',
                    'type'     => 'condition',
                    'operator' => 'date_is',
                    'value'    => '2026-10-21',
                    'value_to' => '',
                ]],
            ])
            ->assertOk();

        $this->assertSame(['BL-7001'], collect($response->json('data'))->pluck('order_id')->all());
    }

    // ─── Cmd+X (cut) ──────────────────────────────────────────────────────────

    public function test_cut_clears_every_cell_of_the_range_across_rows(): void
    {
        $first  = $this->placement(['keyword' => 'seo tools', 'live_link_date' => '10/01/2026']);
        $second = $this->placement(['keyword' => 'ppc ads', 'live_link_date' => '10/02/2026']);

        $this->batchUpdateCells([
            ['id' => $first->id, 'fields' => ['keyword' => '', 'live_link_date' => '']],
            ['id' => $second->id, 'fields' => ['keyword' => '', 'live_link_date' => '']],
        ])
            ->assertOk()
            ->assertJsonPath('updated_count', 2);

        foreach ([$first, $second] as $placement) {
            $fresh = $placement->fresh();
            $this->assertSame('', (string) $fresh->keyword);
            $this->assertSame('', (string) $fresh->live_link_date);
        }
    }

    public function test_cut_of_a_formula_cell_removes_its_formula(): void
    {
        $placement = $this->placement([
            'notes'         => 'KEYWORD',
            'cell_formulas' => ['notes' => '=UPPER([keyword:~0])'],
        ]);

        $this->batchUpdateCells([['id' => $placement->id, 'fields' => ['notes' => ''], 'cell_formulas' => null]])
            ->assertOk();

        $fresh = $placement->fresh();
        $this->assertSame('', (string) $fresh->notes);
        $this->assertNull($fresh->cell_formulas);
    }

    // ─── Cmd+D / Cmd+R (fill down and fill right) ─────────────────────────────

    public function test_fill_down_writes_the_first_row_values_into_every_row_below(): void
    {
        $source  = $this->placement(['pen_name' => 'Jane Writer', 'approval_date' => '10/21/2026']);
        $targets = [$this->placement(), $this->placement(), $this->placement()];

        $updates = array_map(
            fn (LinkBuildingOrderPlacement $target) => [
                'id'     => $target->id,
                'fields' => ['pen_name' => 'Jane Writer', 'approval_date' => '10/21/2026'],
            ],
            $targets
        );

        $response = $this->batchUpdateCells($updates)
            ->assertOk()
            ->assertJsonPath('updated_count', 3);

        foreach ($response->json('data') as $row) {
            $this->assertSame('Jane Writer', $row['pen_name']);
            $this->assertSame('10/21/2026', $row['approval_date']);
        }
        $this->assertSame('Jane Writer', $source->fresh()->pen_name);
    }

    public function test_fill_right_writes_one_value_into_several_columns_of_a_row(): void
    {
        $placement = $this->placement(['live_link_date' => '10/21/2026']);

        $this->batchUpdateCells([[
            'id'     => $placement->id,
            'fields' => ['live_link_date' => '10/21/2026', 'approval_date' => '10/21/2026'],
        ]])->assertOk();

        $this->assertSame('10/21/2026', $placement->fresh()->approval_date);
    }

    public function test_fill_down_of_a_formula_stores_the_same_row_relative_formula_on_every_row(): void
    {
        // The dashboard computes each row's result, the stored formula reads "this row"
        // ([dr_lbs:~0]), so =S1*2 filled down behaves like =S2*2, =S3*2 in Sheets.
        $first  = $this->placement(['dr_lbs' => '40']);
        $second = $this->placement(['dr_lbs' => '15']);
        $formula = ['notes' => '=[dr_lbs:~0]*2'];

        $this->batchUpdateCells([
            ['id' => $first->id, 'fields' => ['notes' => '80'], 'cell_formulas' => $formula],
            ['id' => $second->id, 'fields' => ['notes' => '30'], 'cell_formulas' => $formula],
        ])->assertOk();

        $this->assertSame('80', $first->fresh()->notes);
        $this->assertSame('30', $second->fresh()->notes);
        $this->assertSame($formula, $first->fresh()->cell_formulas);
        $this->assertSame($formula, $second->fresh()->cell_formulas);
    }

    public function test_fill_rejects_a_formula_in_a_column_that_only_takes_fixed_values(): void
    {
        $placement = $this->placement(['status' => 'New Request']);

        $this->batchUpdateCells([[
            'id'            => $placement->id,
            'fields'        => ['status' => 'Reviewing'],
            'cell_formulas' => ['status' => '=[status:~-1]'],
        ]])->assertStatus(422);

        $this->assertSame('New Request', $placement->fresh()->status);
    }

    public function test_fill_never_overwrites_the_locked_date_columns(): void
    {
        $placement = $this->placement([
            'request_date'            => '10/01/2026',
            'estimated_delivery_date' => '10/31/2026',
        ]);

        $this->batchUpdateCells([[
            'id'     => $placement->id,
            'fields' => [
                'request_date'            => '01/01/2020',
                'estimated_delivery_date' => '01/31/2020',
                'live_link_date'          => '10/21/2026',
            ],
        ]])->assertOk();

        $fresh = $placement->fresh();
        $this->assertSame('10/01/2026', $fresh->request_date);
        $this->assertSame('10/31/2026', $fresh->estimated_delivery_date);
        $this->assertSame('10/21/2026', $fresh->live_link_date);
    }

    public function test_fill_never_overwrites_the_order_id(): void
    {
        $first  = $this->placement(['order_id' => 'BL-8001']);
        $second = $this->placement(['order_id' => 'BL-8002']);

        $this->batchUpdateCells([['id' => $second->id, 'fields' => ['order_id' => 'BL-8001', 'keyword' => 'filled']]])
            ->assertOk();

        $this->assertSame('BL-8002', $second->fresh()->order_id);
        $this->assertSame('filled', $second->fresh()->keyword);
        $this->assertSame('BL-8001', $first->fresh()->order_id);
    }

    // ─── Undo of a fill or cut ────────────────────────────────────────────────

    public function test_undo_of_a_fill_restores_every_previous_value(): void
    {
        $first  = $this->placement(['pen_name' => 'Jane Writer']);
        $second = $this->placement(['pen_name' => 'Other Writer']);

        $this->batchUpdateCells([['id' => $second->id, 'fields' => ['pen_name' => 'Jane Writer']]])->assertOk();
        $this->batchUpdateCells([['id' => $second->id, 'fields' => ['pen_name' => 'Other Writer']]])->assertOk();

        $this->assertSame('Jane Writer', $first->fresh()->pen_name);
        $this->assertSame('Other Writer', $second->fresh()->pen_name);
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_a_client_cannot_cut_or_fill_cells(): void
    {
        $client = User::factory()->create(['is_active' => true]);
        $client->assignRole('client');
        $placement = $this->placement(['keyword' => 'seo tools']);

        $this->actingAs($client, 'api')
            ->postJson(self::BATCH_CELLS_URL, ['updates' => [['id' => $placement->id, 'fields' => ['keyword' => '']]]])
            ->assertStatus(403);

        $this->assertSame('seo tools', $placement->fresh()->keyword);
    }
}
