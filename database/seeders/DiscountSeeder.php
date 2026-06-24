<?php

namespace Database\Seeders;

use App\Models\Discount;
use Illuminate\Database\Seeder;

class DiscountSeeder extends Seeder
{
    public function run(): void
    {
        Discount::updateOrCreate(
            ['discount_type' => 'bulk', 'applies_to' => 'link_building'],
            [
                'name'          => 'Bulk Link Building Discount',
                'description'   => 'Applies a 10% discount when 10 or more links are ordered in a single cart.',
                'discount_type' => 'bulk',
                'discount_rate' => 10.00,
                'min_quantity'  => 10,
                'applies_to'    => 'link_building',
                'is_active'     => true,
            ]
        );
    }
}
