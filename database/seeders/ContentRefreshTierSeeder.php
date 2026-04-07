<?php

namespace Database\Seeders;

use App\Models\ContentRefreshTier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ContentRefreshTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'label'            => 'Current Content Word Count 0-799',
                'word_count_range' => '0-799',
                'turnaround_days'  => 5,
                'price'            => 220.00,
                'is_active'        => true,
                'sort_order'       => 1,
            ],
            [
                'label'            => 'Current Content Word Count 800-1,599',
                'word_count_range' => '800-1,599',
                'turnaround_days'  => 7,
                'price'            => 275.00,
                'is_active'        => true,
                'sort_order'       => 2,
            ],
            [
                'label'            => 'Current Content Word Count 1,600+',
                'word_count_range' => '1,600+',
                'turnaround_days'  => 9,
                'price'            => 440.00,
                'is_active'        => true,
                'sort_order'       => 3,
            ],
        ];

        foreach ($tiers as $tier) {
            ContentRefreshTier::updateOrCreate(
                ['word_count_range' => $tier['word_count_range']],
                array_merge($tier, ['id' => (string) Str::uuid()])
            );
        }
    }
}
