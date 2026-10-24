<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingPartnerMetric;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dashboard colors the Link Table columns like the BASE links Google Sheet:
 *
 * - DR and Posting Fee (dr_lbs, posting_fee_lbs) are entered by Link Builders
 *   themselves and share one color.
 * - Current Traffic, DR Formula, Current POC and Current Price are looked up from
 *   the row's Partnership and share another color.
 *
 * These tests check that the API keeps that split: the looked up columns follow
 * the Partnership, while the Link Builder columns keep whatever was typed in.
 */
class LinkBuildingOrderColumnGroupsTest extends TestCase
{
    use RefreshDatabase;

    private const LINK_BUILDER_COLUMNS = ['dr_lbs', 'posting_fee_lbs'];

    private const TRAFFIC_AND_PRICING_COLUMNS = ['current_traffic', 'dr_formula', 'current_poc', 'current_price'];

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

    // ─── Helpers ──────────────────────────────────────────────────────────────

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
            'dr_lbs'          => '41',
            'posting_fee_lbs' => '$45.00',
            'dr_formula'      => '38',
            'current_traffic' => '900',
            'current_poc'     => 'Sarah',
            'current_price'   => '$30.00',
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
            'dr_lbs'          => $placement->dr_lbs,
            'posting_fee_lbs' => $placement->posting_fee_lbs,
            'dr_formula'      => $placement->dr_formula,
            'current_traffic' => $placement->current_traffic,
            'current_poc'     => $placement->current_poc,
            'current_price'   => $placement->current_price,
        ], $overrides);
    }

    // ─── Reading the columns ──────────────────────────────────────────────────

    public function test_search_returns_every_column_of_both_groups(): void
    {
        $this->placement();

        $row = $this->actingAs($this->admin)
            ->postJson('/api/admin/link-building-orders/search')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('41', $row['dr_lbs']);
        $this->assertSame('$45.00', $row['posting_fee_lbs']);
        $this->assertSame('900', $row['current_traffic']);
        $this->assertSame('38', $row['dr_formula']);
        $this->assertSame('Sarah', $row['current_poc']);
        $this->assertSame('$30.00', $row['current_price']);
    }

    public static function columnValueProvider(): array
    {
        return [
            'DR'              => ['dr_lbs', '41'],
            'Posting Fee'     => ['posting_fee_lbs', '$45.00'],
            'Current Traffic' => ['current_traffic', '900'],
            'DR Formula'      => ['dr_formula', '38'],
            'Current POC'     => ['current_poc', 'Sarah'],
            'Current Price'   => ['current_price', '$30.00'],
        ];
    }

    #[DataProvider('columnValueProvider')]
    public function test_copy_column_returns_the_values_of_each_regrouped_column(string $column, string $expected_value): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/link-building-orders/column-values', [
                'column'  => $column,
                'row_ids' => [$placement->id],
            ])
            ->assertOk()
            ->assertJsonPath('data', [$expected_value])
            ->assertJsonPath('count', 1);
    }

    // ─── Link Builder columns ─────────────────────────────────────────────────

    public function test_link_builder_can_save_dr_and_posting_fee_without_touching_the_looked_up_columns(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin)
            ->putJson(
                "/api/admin/link-building-orders/{$placement->id}",
                $this->fullRowPayload($placement, ['dr_lbs' => '55', 'posting_fee_lbs' => '60'])
            )
            ->assertOk()
            ->assertJsonPath('data.dr_lbs', '55')
            ->assertJsonPath('data.posting_fee_lbs', '$60.00')
            ->assertJsonPath('data.current_traffic', '900')
            ->assertJsonPath('data.dr_formula', '38')
            ->assertJsonPath('data.current_poc', 'Sarah')
            ->assertJsonPath('data.current_price', '$30.00');
    }

    // ─── Changing the Partnership ─────────────────────────────────────────────

    public function test_new_partnership_fills_the_traffic_and_pricing_columns_and_keeps_dr_and_posting_fee(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin)
            ->putJson(
                "/api/admin/link-building-orders/{$placement->id}",
                $this->fullRowPayload($placement, ['partnership' => 'techkaya.com'])
            )
            ->assertOk()
            ->assertJsonPath('data.current_traffic', '2300')
            ->assertJsonPath('data.dr_formula', '74')
            ->assertJsonPath('data.current_poc', 'David')
            ->assertJsonPath('data.current_price', '$20.00')
            ->assertJsonPath('data.dr_lbs', '41')
            ->assertJsonPath('data.posting_fee_lbs', '$45.00');

        $fresh = $placement->fresh();
        $this->assertSame('41', $fresh->dr_lbs);
        $this->assertSame('$45.00', $fresh->posting_fee_lbs);
    }

    public function test_site_outside_the_partner_list_flags_poc_and_price_but_keeps_dr_and_posting_fee(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin)
            ->putJson(
                "/api/admin/link-building-orders/{$placement->id}",
                $this->fullRowPayload($placement, ['partnership' => 'unknown-site.com'])
            )
            ->assertOk()
            ->assertJsonPath('data.current_poc', LinkBuildingPartnerMetric::SITE_REMOVED)
            ->assertJsonPath('data.current_price', LinkBuildingPartnerMetric::SITE_REMOVED)
            ->assertJsonPath('data.dr_lbs', '41')
            ->assertJsonPath('data.posting_fee_lbs', '$45.00');
    }

    public function test_pasted_partnership_with_dr_and_posting_fee_keeps_the_link_builder_values(): void
    {
        $placement = $this->placement();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [[
                    'id'     => $placement->id,
                    'fields' => [
                        'partnership'     => 'techkaya.com',
                        'dr_lbs'          => '70',
                        'posting_fee_lbs' => '80',
                    ],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 1);

        $fresh = $placement->fresh();
        $this->assertSame('70', $fresh->dr_lbs);
        $this->assertSame('$80.00', $fresh->posting_fee_lbs);
        $this->assertSame('2300', $fresh->current_traffic);
        $this->assertSame('74', $fresh->dr_formula);
        $this->assertSame('David', $fresh->current_poc);
        $this->assertSame('$20.00', $fresh->current_price);
    }

    public function test_bulk_fill_of_the_traffic_and_pricing_columns_leaves_dr_and_posting_fee_alone(): void
    {
        $first  = $this->placement();
        $second = $this->placement(['dr_lbs' => '52', 'posting_fee_lbs' => '$65.00']);

        $updates = array_map(fn (LinkBuildingOrderPlacement $placement) => [
            'id'     => $placement->id,
            'fields' => [
                'current_traffic' => '5000',
                'dr_formula'      => '60',
                'current_poc'     => 'Mia',
                'current_price'   => '99',
            ],
        ], [$first, $second]);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/link-building-orders/batch-update-cells', ['updates' => $updates])
            ->assertOk()
            ->assertJsonPath('updated_count', 2);

        foreach ([[$first, '41', '$45.00'], [$second, '52', '$65.00']] as [$placement, $dr, $posting_fee]) {
            $fresh = $placement->fresh();
            $this->assertSame('5000', $fresh->current_traffic);
            $this->assertSame('60', $fresh->dr_formula);
            $this->assertSame('Mia', $fresh->current_poc);
            $this->assertSame('$99.00', $fresh->current_price);
            $this->assertSame($dr, $fresh->dr_lbs);
            $this->assertSame($posting_fee, $fresh->posting_fee_lbs);
        }
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    public static function maxLengthProvider(): array
    {
        return [
            'DR'              => ['dr_lbs', 50],
            'Posting Fee'     => ['posting_fee_lbs', 50],
            'Current Traffic' => ['current_traffic', 50],
            'DR Formula'      => ['dr_formula', 50],
            'Current POC'     => ['current_poc', 255],
            'Current Price'   => ['current_price', 100],
        ];
    }

    #[DataProvider('maxLengthProvider')]
    public function test_update_rejects_a_value_over_the_column_limit(string $column, int $max_length): void
    {
        $placement = $this->placement();
        $original  = $placement->{$column};

        $this->actingAs($this->admin)
            ->putJson("/api/admin/link-building-orders/{$placement->id}", [$column => str_repeat('a', $max_length + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors($column);

        $this->assertSame($original, $placement->fresh()->{$column});
    }

    public function test_client_cannot_edit_the_regrouped_columns(): void
    {
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);
        $client = User::factory()->create(['is_active' => true]);
        $client->assignRole('client');
        $placement = $this->placement();

        $this->actingAs($client, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['dr_lbs' => '99', 'current_traffic' => '1'])
            ->assertStatus(403);

        $fresh = $placement->fresh();
        $this->assertSame('41', $fresh->dr_lbs);
        $this->assertSame('900', $fresh->current_traffic);
    }

    /** Guards the list the color groups are built on: every column of both groups stays in the API row. */
    public function test_api_row_exposes_every_column_of_both_groups(): void
    {
        $row = $this->placement()->toApiArray();

        foreach ([...self::LINK_BUILDER_COLUMNS, ...self::TRAFFIC_AND_PRICING_COLUMNS] as $column) {
            $this->assertArrayHasKey($column, $row);
        }
    }
}
