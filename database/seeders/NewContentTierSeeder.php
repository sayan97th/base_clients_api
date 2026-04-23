<?php

namespace Database\Seeders;

use App\Models\NewContentTier;
use Illuminate\Database\Seeder;

class NewContentTierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tiers = [
            [
                'tier_id' => 'article_500',
                'label' => '500 Word Optimized SEO Article',
                'turnaround_time' => '6 Business Days',
                'price' => 300.00,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'tier_id' => 'article_600',
                'label' => '600 Word Optimized SEO Article',
                'turnaround_time' => '6 Business Days',
                'price' => 330.00,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'tier_id' => 'article_750',
                'label' => '750 Word Optimized SEO Article',
                'turnaround_time' => '6 Business Days',
                'price' => 425.00,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'tier_id' => 'article_1000',
                'label' => '1,000 Word Optimized SEO Article',
                'turnaround_time' => '7 Business Days',
                'price' => 550.00,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'tier_id' => 'article_1500',
                'label' => '1,500 Word Optimized SEO Article',
                'turnaround_time' => '7 Business Days',
                'price' => 775.00,
                'is_active' => true,
                'sort_order' => 5,
            ],
        ];

        foreach ($tiers as $tier) {
            NewContentTier::updateOrCreate(
                ['tier_id' => $tier['tier_id']],
                $tier
            );
        }
    }
}
