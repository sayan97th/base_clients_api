<?php

namespace Database\Seeders;

use App\Models\DrTier;
use Illuminate\Database\Seeder;

class DrTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'id'             => 'dr_30',
                'dr_label'       => 'DR 30+',
                'traffic_range'  => '800-5,000+',
                'word_count'     => 650,
                'price_per_link' => 260.00,
                'is_most_popular' => false,
                'is_active'      => true,
            ],
            [
                'id'             => 'dr_40',
                'dr_label'       => 'DR 40+',
                'traffic_range'  => '1,000-10,000+',
                'word_count'     => 700,
                'price_per_link' => 315.00,
                'is_most_popular' => false,
                'is_active'      => true,
            ],
            [
                'id'             => 'dr_50',
                'dr_label'       => 'DR 50+',
                'traffic_range'  => '2,000-20,000+',
                'word_count'     => 750,
                'price_per_link' => 400.00,
                'is_most_popular' => true,
                'is_active'      => true,
            ],
            [
                'id'             => 'dr_60',
                'dr_label'       => 'DR 60+',
                'traffic_range'  => '5,000-50,000+',
                'word_count'     => 800,
                'price_per_link' => 500.00,
                'is_most_popular' => false,
                'is_active'      => true,
            ],
            [
                'id'             => 'dr_70',
                'dr_label'       => 'DR 70+',
                'traffic_range'  => '10,000-100,000+',
                'word_count'     => 900,
                'price_per_link' => 650.00,
                'is_most_popular' => false,
                'is_active'      => true,
            ],
        ];

        foreach ($tiers as $tier) {
            DrTier::updateOrCreate(['id' => $tier['id']], $tier);
        }
    }
}
