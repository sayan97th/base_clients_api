<?php

namespace Database\Seeders;

use App\Models\SmeCollaborationTier;
use Illuminate\Database\Seeder;

class SmeCollaborationTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'tier_key'    => 'sme_1000_1499',
                'label'       => 'Internal SME Content Collaboration - 1,000-1,499 Words',
                'description' => "We interview your company's internal experts and transform their insights into polished, audience-ready content.",
                'price'       => 750,
                'sort_order'  => 1,
                'is_active'   => true,
            ],
            [
                'tier_key'    => 'sme_1500_1999',
                'label'       => 'Internal SME Content Collaboration - 1,500-1,999 Words',
                'description' => "We interview your company's internal experts and transform their insights into polished, audience-ready content.",
                'price'       => 1250,
                'sort_order'  => 2,
                'is_active'   => true,
            ],
            [
                'tier_key'    => 'sme_2000_plus',
                'label'       => 'Internal SME Content Collaboration - 2,000+ Words',
                'description' => "We interview your company's internal experts and transform their insights into polished, audience-ready content.",
                'price'       => 1500,
                'sort_order'  => 3,
                'is_active'   => true,
            ],
        ];

        foreach ($tiers as $tier) {
            SmeCollaborationTier::updateOrCreate(['tier_key' => $tier['tier_key']], $tier);
        }
    }
}
