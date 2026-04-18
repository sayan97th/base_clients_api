<?php

namespace Database\Seeders;

use App\Models\PremiumMentionsPlan;
use Illuminate\Database\Seeder;

class PremiumMentionsPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'                  => 'Authority Boost',
                'price_per_month'       => 3000.00,
                'total_placements'      => 5,
                'exclusive_placements'  => 1,
                'core_placements'       => 2,
                'support_placements'    => 2,
                'best_for'              => 'Initial brand visibility on legitimate publications.',
                'tagline'               => 'Get the brand seen.',
                'is_most_popular'       => false,
                'is_active'             => true,
            ],
            [
                'name'                  => 'Authority Growth',
                'price_per_month'       => 4500.00,
                'total_placements'      => 7,
                'exclusive_placements'  => 2,
                'core_placements'       => 2,
                'support_placements'    => 3,
                'best_for'              => 'Building repeated exposure and a recognizable editorial presence.',
                'tagline'               => 'Build recognizable authority.',
                'is_most_popular'       => true,
                'is_active'             => true,
            ],
            [
                'name'                  => 'Authority Established',
                'price_per_month'       => 6000.00,
                'total_placements'      => 10,
                'exclusive_placements'  => 3,
                'core_placements'       => 3,
                'support_placements'    => 4,
                'best_for'              => 'Brands seeking top-tier credibility across trusted publications.',
                'tagline'               => 'Established top-tier credibility.',
                'is_most_popular'       => false,
                'is_active'             => true,
            ],
        ];

        foreach ($plans as $plan) {
            PremiumMentionsPlan::updateOrCreate(['name' => $plan['name']], $plan);
        }
    }
}
