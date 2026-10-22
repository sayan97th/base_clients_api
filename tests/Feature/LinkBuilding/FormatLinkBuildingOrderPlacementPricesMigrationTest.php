<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The data migration that formats the prices saved before prices were formatted
 * automatically (2026_10_22_000001_format_link_building_order_placement_prices).
 */
class FormatLinkBuildingOrderPlacementPricesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_22_000001_format_link_building_order_placement_prices.php';

    /** Inserts a row the way old data was saved, without the model formatting it. */
    private function insertLegacyRow(array $values): string
    {
        $id = Str::uuid()->toString();

        DB::table('link_building_order_placements')->insert(array_merge([
            'id'           => $id,
            'order_id'     => 'BL-' . rand(10000, 99999),
            'client'       => 'Acme',
            'keyword'      => 'keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
            'currency'     => 'USD',
            'created_at'   => '2026-01-01 00:00:00',
            'updated_at'   => '2026-01-01 00:00:00',
        ], $values));

        return $id;
    }

    private function runMigration(): void
    {
        (require database_path('migrations/' . self::MIGRATION))->up();
    }

    private function row(string $id): object
    {
        return DB::table('link_building_order_placements')->where('id', $id)->first();
    }

    public function test_plain_amounts_get_the_symbol_of_their_row_currency(): void
    {
        $usd = $this->insertLegacyRow(['posting_fee_lbs' => '50', 'current_price' => '$1200', 'final_price' => '1380.5']);
        $eur = $this->insertLegacyRow(['currency' => 'EUR', 'final_price' => '$99']);

        $this->runMigration();

        $this->assertSame('$50.00', $this->row($usd)->posting_fee_lbs);
        $this->assertSame('$1,200.00', $this->row($usd)->current_price);
        $this->assertSame('$1,380.50', $this->row($usd)->final_price);
        $this->assertSame('€99.00', $this->row($eur)->final_price);
    }

    public function test_text_empty_and_formatted_prices_are_left_untouched(): void
    {
        $id = $this->insertLegacyRow(['posting_fee_lbs' => 'TBD', 'current_price' => null, 'final_price' => '$250.00']);

        $this->runMigration();

        $row = $this->row($id);
        $this->assertSame('TBD', $row->posting_fee_lbs);
        $this->assertNull($row->current_price);
        $this->assertSame('$250.00', $row->final_price);
    }

    public function test_updated_at_and_the_other_columns_are_kept(): void
    {
        $id = $this->insertLegacyRow(['final_price' => '150', 'dr_lbs' => '45', 'keyword' => 'seo tools']);

        $this->runMigration();

        $row = $this->row($id);
        $this->assertSame('2026-01-01 00:00:00', $row->updated_at);
        $this->assertSame('45', $row->dr_lbs);
        $this->assertSame('seo tools', $row->keyword);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $id = $this->insertLegacyRow(['final_price' => '1250']);

        $this->runMigration();
        $this->runMigration();

        $this->assertSame('$1,250.00', $this->row($id)->final_price);
    }

    public function test_every_row_is_formatted_across_several_chunks(): void
    {
        $ids = collect(range(1, 520))->map(fn (int $n) => $this->insertLegacyRow(['order_id' => "BL-{$n}", 'final_price' => (string) $n]));

        $this->runMigration();

        $this->assertSame(0, DB::table('link_building_order_placements')->whereIn('id', $ids)->where('final_price', 'not like', '$%')->count());
        $this->assertSame('$520.00', $this->row($ids->last())->final_price);
        $this->assertSame(520, LinkBuildingOrderPlacement::count());
    }
}
