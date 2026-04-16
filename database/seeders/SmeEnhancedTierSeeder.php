<?php

namespace Database\Seeders;

use App\Models\SmeEnhancedTier;
use Illuminate\Database\Seeder;

class SmeEnhancedTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'tier_key'    => 'sme_enhanced_1000',
                'label'       => 'SME Enhanced Content - 1,000-1,499 Words',
                'description' => 'We write the content and have a qualified SME review it for technical accuracy and put their name on the article.',
                'price'       => 1500,
                'sort_order'  => 1,
                'is_active'   => true,
            ],
            [
                'tier_key'    => 'sme_enhanced_1500',
                'label'       => 'SME Enhanced Content - 1,500-1,999 Words',
                'description' => 'We write the content and have a qualified SME review it for technical accuracy and put their name on the article.',
                'price'       => 2500,
                'sort_order'  => 2,
                'is_active'   => true,
            ],
            [
                'tier_key'    => 'sme_enhanced_2000',
                'label'       => 'SME Enhanced Content - 2,000+ Words',
                'description' => 'We write the content and have a qualified SME review it for technical accuracy and put their name on the article.',
                'price'       => 3500,
                'sort_order'  => 3,
                'is_active'   => true,
            ],
        ];

        foreach ($tiers as $tier) {
            SmeEnhancedTier::updateOrCreate(['tier_key' => $tier['tier_key']], $tier);
        }
    }
}
