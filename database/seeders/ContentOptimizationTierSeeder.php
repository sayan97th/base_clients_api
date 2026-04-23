<?php

namespace Database\Seeders;

use App\Models\ContentOptimizationTier;
use Illuminate\Database\Seeder;

class ContentOptimizationTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'id'               => 'optimization_0_799',
                'label'            => 'Current Content Word Count 0-799',
                'word_count_range' => '0-799',
                'turnaround_days'  => 5,
                'price'            => 220.00,
                'is_active'        => true,
                'is_most_popular'  => false,
                'max_quantity'     => null,
                'is_hidden'        => false,
                'sort_order'       => 1,
            ],
            [
                'id'               => 'optimization_800_1599',
                'label'            => 'Current Content Word Count 800-1,599',
                'word_count_range' => '800-1599',
                'turnaround_days'  => 7,
                'price'            => 275.00,
                'is_active'        => true,
                'is_most_popular'  => true,
                'max_quantity'     => null,
                'is_hidden'        => false,
                'sort_order'       => 2,
            ],
            [
                'id'               => 'optimization_1600_plus',
                'label'            => 'Current Content Word Count 1,600+',
                'word_count_range' => '1600+',
                'turnaround_days'  => 9,
                'price'            => 440.00,
                'is_active'        => true,
                'is_most_popular'  => false,
                'max_quantity'     => null,
                'is_hidden'        => false,
                'sort_order'       => 3,
            ],
        ];

        foreach ($tiers as $tier) {
            ContentOptimizationTier::updateOrCreate(
                ['id' => $tier['id']],
                $tier
            );
        }
    }
}
