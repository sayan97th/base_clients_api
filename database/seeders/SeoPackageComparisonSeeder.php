<?php

namespace Database\Seeders;

use App\Models\SeoComparisonRow;
use Illuminate\Database\Seeder;

class SeoPackageComparisonSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'label'      => 'Strategy',
                'sort_order' => 1,
                'values'     => [
                    'growth-seo-plan'      => 'Foundation',
                    'performance-seo-plan' => 'Advanced',
                    'full-scale-seo-plan'  => 'Advanced + competitive',
                ],
            ],
            [
                'label'      => 'Content',
                'sort_order' => 2,
                'values'     => [
                    'growth-seo-plan'      => 'Optimize existing',
                    'performance-seo-plan' => 'New content',
                    'full-scale-seo-plan'  => 'High-volume content',
                ],
            ],
            [
                'label'      => 'Link building',
                'sort_order' => 3,
                'values'     => [
                    'growth-seo-plan'      => '✓',
                    'performance-seo-plan' => '✓ ✓',
                    'full-scale-seo-plan'  => '✓ ✓ ✓',
                ],
            ],
            [
                'label'      => 'Technical SEO',
                'sort_order' => 4,
                'values'     => [
                    'growth-seo-plan'      => 'Not included',
                    'performance-seo-plan' => 'Audits',
                    'full-scale-seo-plan'  => 'Managed',
                ],
            ],
            [
                'label'      => 'Reporting & calls',
                'sort_order' => 5,
                'values'     => [
                    'growth-seo-plan'      => 'Monthly',
                    'performance-seo-plan' => 'Monthly + quarterly review',
                    'full-scale-seo-plan'  => 'Live + deep quarterly review',
                ],
            ],
            [
                'label'      => 'Monthly price',
                'sort_order' => 6,
                'values'     => [
                    'growth-seo-plan'      => '$999',
                    'performance-seo-plan' => '$2,999',
                    'full-scale-seo-plan'  => '$4,999',
                ],
            ],
        ];

        foreach ($rows as $row_data) {
            $row = SeoComparisonRow::updateOrCreate(
                ['label' => $row_data['label']],
                ['sort_order' => $row_data['sort_order']]
            );

            foreach ($row_data['values'] as $package_id => $value) {
                $row->values()->updateOrCreate(
                    ['seo_package_id' => $package_id],
                    ['value' => $value]
                );
            }
        }
    }
}
