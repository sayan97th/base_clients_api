<?php

namespace Tests\Feature\Discount\PromoCodeStacking;

use App\Models\Discount;
use Database\Seeders\DiscountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Database side of the option: the migration adds a column that is off for
 * every discount, including the ones created before it existed, and the
 * seeder never overrides what an admin chose.
 */
class PromoCodeStackingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_discounts_table_has_the_stacking_column(): void
    {
        $this->assertTrue(Schema::hasColumn('discounts', 'allow_promo_code_stacking'));
    }

    public function test_rows_inserted_without_the_column_do_not_allow_stacking(): void
    {
        // Mirrors discounts that existed before the migration ran.
        $id = (string) Str::uuid();
        DB::table('discounts')->insert([
            'id'            => $id,
            'name'          => 'Legacy bulk',
            'discount_type' => 'bulk',
            'discount_rate' => 10,
            'min_quantity'  => 10,
            'applies_to'    => 'link_building',
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->assertFalse(Discount::findOrFail($id)->allow_promo_code_stacking);
    }

    public function test_the_seeded_bulk_discount_does_not_allow_stacking(): void
    {
        $this->seed(DiscountSeeder::class);

        $discount = Discount::where('applies_to', 'link_building')->firstOrFail();
        $this->assertFalse($discount->allow_promo_code_stacking);
    }

    public function test_running_the_seeder_again_keeps_the_admin_choice(): void
    {
        $this->seed(DiscountSeeder::class);
        Discount::where('applies_to', 'link_building')->update(['allow_promo_code_stacking' => true]);

        $this->seed(DiscountSeeder::class);

        $this->assertTrue(Discount::where('applies_to', 'link_building')->firstOrFail()->allow_promo_code_stacking);
    }
}
