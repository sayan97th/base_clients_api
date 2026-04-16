<?php

namespace Database\Seeders;

use App\Models\SmeAuthoredTier;
use Illuminate\Database\Seeder;

class SmeAuthoredTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'tier_key'    => 'sme_authored_1000_1499',
                'label'       => 'SME Authored Content - 1,000-1,499 Words',
                'description' => 'Industry experts with verified credentials create comprehensive content from research to final delivery with editorial oversight.',
                'price'       => 2000,
                'sort_order'  => 1,
                'is_active'   => true,
            ],
            [
                'tier_key'    => 'sme_authored_1500_1999',
                'label'       => 'SME Authored Content - 1,500-1,999 Words',
                'description' => 'Industry experts with verified credentials create comprehensive content from research to final delivery with editorial oversight.',
                'price'       => 3000,
                'sort_order'  => 2,
                'is_active'   => true,
            ],
            [
                'tier_key'    => 'sme_authored_2000_plus',
                'label'       => 'SME Authored Content - 2,000+ Words',
                'description' => 'Industry experts with verified credentials create comprehensive content from research to final delivery with editorial oversight.',
                'price'       => 4000,
                'sort_order'  => 3,
                'is_active'   => true,
            ],
        ];

        foreach ($tiers as $tier) {
            SmeAuthoredTier::updateOrCreate(['tier_key' => $tier['tier_key']], $tier);
        }
    }
}
