<?php

namespace Database\Seeders;

use App\Models\ContentBriefTier;
use Illuminate\Database\Seeder;

class ContentBriefTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'id'              => 'content_brief_outline',
                'label'           => 'Content Brief/Outline',
                'turnaround_days' => 5,
                'price'           => 99.00,
                'is_active'       => true,
                'is_most_popular' => false,
                'max_quantity'    => null,
                'is_hidden'       => false,
                'sort_order'      => 1,
            ],
        ];

        foreach ($tiers as $tier) {
            ContentBriefTier::updateOrCreate(
                ['id' => $tier['id']],
                $tier
            );
        }
    }
}
