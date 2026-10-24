<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin "Link Building Orders" table shows a Google Sheets style summary
 * (Sum, Average, Min, Max, Count) of the cells selected on the page. It is
 * computed in the browser from the rows the search endpoint returns, so these
 * tests pin the shape of those values: prices and numbers must read as numbers
 * ("$1,250.00", "€99.00", "40"), and empty cells must come back as "" so they
 * are left out of Count.
 */
class LinkBuildingOrderSelectionSummaryTest extends TestCase
{
    use RefreshDatabase;

    /** Free text number columns the summary adds up besides the prices. */
    private const NUMBER_COLUMNS = ['dr_lbs', 'current_traffic', 'dr_formula'];

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

    /** Rows of the first page of the dashboard search, keyed by order id. */
    private function searchRows(array $payload = []): array
    {
        $rows = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', $payload)
            ->assertOk()
            ->json('data');

        return collect($rows)->keyBy('order_id')->all();
    }

    /**
     * Same rules as parseNumericCell() in the portal (src/lib/spreadsheet-navigation.ts):
     * currency symbols, thousands commas and spaces are dropped, then the rest must be a plain number.
     */
    private function parseLikeSelectionSummary(string $value): ?float
    {
        $cleaned = preg_replace('/[$€£,%\s]/u', '', trim($value));

        if ($cleaned === '' || ! preg_match('/^[-+]?(\d+\.?\d*|\.\d+)$/', $cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    public static function priceAmounts(): array
    {
        return [
            'whole dollars'      => ['USD', '50', 50.0],
            'thousands'          => ['USD', '1250', 1250.0],
            'millions'           => ['USD', '1250000', 1250000.0],
            'cents'              => ['USD', '1380.5', 1380.5],
            'typed with a comma' => ['USD', '1,000', 1000.0],
            'negative'           => ['USD', '-50', -50.0],
            'euros'              => ['EUR', '1250', 1250.0],
        ];
    }

    #[DataProvider('priceAmounts')]
    public function test_every_price_column_is_returned_as_a_number_the_summary_can_add_up(
        string $currency,
        string $typed_amount,
        float $expected_amount
    ): void {
        $prices = array_fill_keys(LinkBuildingOrderPlacement::PRICE_COLUMNS, $typed_amount);
        $this->placement(['order_id' => 'BL-8001', 'currency' => $currency, ...$prices]);

        $row = $this->searchRows()['BL-8001'];

        foreach (LinkBuildingOrderPlacement::PRICE_COLUMNS as $column) {
            $this->assertIsString($row[$column], "{$column} must be sent as text");
            $this->assertSame(
                $expected_amount,
                $this->parseLikeSelectionSummary($row[$column]),
                "{$column} came back as \"{$row[$column]}\", which the selection summary cannot read as {$expected_amount}"
            );
        }
    }

    public function test_euro_prices_keep_the_comma_for_thousands_and_the_dot_for_decimals(): void
    {
        // "€1.250,00" would read as 1.25 in the summary, so the European style must not be used.
        $this->placement(['order_id' => 'BL-8002', 'currency' => 'EUR', 'final_price' => '1250.75']);

        $this->assertSame('€1,250.75', $this->searchRows()['BL-8002']['final_price']);
    }

    public function test_number_columns_are_returned_as_typed(): void
    {
        $this->placement([
            'order_id'                  => 'BL-8003',
            'estimated_turnaround_days' => 30,
            'dr_lbs'                    => '47',
            'current_traffic'           => '12,500',
            'dr_formula'                => '3.5',
        ]);

        $row = $this->searchRows()['BL-8003'];

        $this->assertSame(30.0, $this->parseLikeSelectionSummary($row['estimated_turnaround_days']));
        $this->assertSame(47.0, $this->parseLikeSelectionSummary($row['dr_lbs']));
        $this->assertSame(12500.0, $this->parseLikeSelectionSummary($row['current_traffic']));
        $this->assertSame(3.5, $this->parseLikeSelectionSummary($row['dr_formula']));
    }

    public function test_empty_number_and_price_cells_are_empty_strings_so_count_skips_them(): void
    {
        $this->placement(['order_id' => 'BL-8004']);

        $row = $this->searchRows()['BL-8004'];

        foreach ([...LinkBuildingOrderPlacement::PRICE_COLUMNS, ...self::NUMBER_COLUMNS] as $column) {
            $this->assertSame('', $row[$column], "An empty {$column} must be sent as \"\", not null or 0");
        }
    }

    public function test_text_typed_in_a_number_column_stays_text_and_is_not_summed(): void
    {
        $this->placement(['order_id' => 'BL-8005', 'dr_lbs' => 'pending', 'final_price' => 'TBD']);

        $row = $this->searchRows()['BL-8005'];

        $this->assertNull($this->parseLikeSelectionSummary($row['dr_lbs']));
        $this->assertNull($this->parseLikeSelectionSummary($row['final_price']));
    }

    public function test_a_column_of_a_full_page_adds_up_to_the_sum_of_its_rows(): void
    {
        $this->placement(['order_id' => 'BL-8101', 'final_price' => '1200']);
        $this->placement(['order_id' => 'BL-8102', 'final_price' => '300', 'currency' => 'EUR']);
        $this->placement(['order_id' => 'BL-8103', 'final_price' => '']);
        $this->placement(['order_id' => 'BL-8104', 'final_price' => '49.99']);

        $values = collect($this->searchRows())->pluck('final_price');
        $numbers = $values->map(fn (string $value) => $this->parseLikeSelectionSummary($value))->filter(fn ($value) => $value !== null);

        $this->assertSame(3, $values->filter(fn (string $value) => trim($value) !== '')->count());
        $this->assertEqualsWithDelta(1549.99, $numbers->sum(), 0.001);
        $this->assertSame(49.99, $numbers->min());
        $this->assertSame(1200.0, $numbers->max());
    }

    public function test_a_formula_result_saved_in_a_price_cell_is_returned_as_its_value(): void
    {
        $placement = $this->placement(['order_id' => 'BL-8006', 'dr_lbs' => '45']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [[
                    'id'            => $placement->id,
                    'fields'        => ['final_price' => '90'],
                    'cell_formulas' => ['final_price' => '=[dr_lbs:~0]*2'],
                ]],
            ])
            ->assertOk();

        $this->assertSame(90.0, $this->parseLikeSelectionSummary($this->searchRows()['BL-8006']['final_price']));
    }
}
