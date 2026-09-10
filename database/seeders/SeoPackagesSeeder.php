<?php

namespace Database\Seeders;

use App\Models\SeoPackage;
use Illuminate\Database\Seeder;

class SeoPackagesSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'id'              => 'growth-seo-plan',
                'name'            => 'Starter',
                'headline'        => 'Build a Strong Foundation',
                'slug'            => 'starter',
                'price_per_month' => 999.00,
                'best_for'        => 'Perfect for businesses looking to establish steady rankings and drive consistent traffic.',
                'ideal_for'       => 'Businesses with existing content that need consistent optimization and authority building.',
                'is_most_popular' => false,
                'is_active'       => true,
                'sort_order'      => 1,
                'features'        => [
                    [
                        'title'       => 'On-page optimization',
                        'description' => 'Improve existing pages for rankings and conversions.',
                    ],
                    [
                        'title'       => 'Manual link building',
                        'description' => 'Quality, relevant outreach.',
                    ],
                    [
                        'title'       => 'Quarterly SEO roadmap',
                        'description' => 'Clear priorities and next steps.',
                    ],
                    [
                        'title'       => 'Monthly performance reporting',
                        'description' => 'Track rankings, traffic and progress.',
                    ],
                ],
            ],
            [
                'id'              => 'performance-seo-plan',
                'name'            => 'Growth',
                'headline'        => 'Accelerate Organic Growth',
                'slug'            => 'growth',
                'price_per_month' => 2999.00,
                'best_for'        => 'For companies ready to turn SEO into a meaningful acquisition channel.',
                'ideal_for'       => 'Growth-stage companies that want SEO to become a real revenue driver.',
                'is_most_popular' => true,
                'is_active'       => true,
                'sort_order'      => 2,
                'features'        => [
                    [
                        'title'       => 'Competitive SEO strategy',
                        'description' => 'Target high-value keywords and opportunities.',
                    ],
                    [
                        'title'       => 'New SEO content',
                        'description' => 'Blog posts and landing pages (e.g. 3/month).',
                    ],
                    [
                        'title'       => 'Technical SEO audits',
                        'description' => 'Improve site health, crawlability and speed.',
                    ],
                    [
                        'title'       => 'Expanded link building',
                        'description' => 'More authority-building outreach.',
                    ],
                    [
                        'title'       => 'Quarterly strategy review',
                        'description' => 'Analyze results and plan next quarter.',
                    ],
                ],
            ],
            [
                'id'              => 'full-scale-seo-plan',
                'name'            => 'Full-Scale',
                'headline'        => 'Dominate Competitive Search',
                'slug'            => 'full-scale',
                'price_per_month' => 4999.00,
                'best_for'        => 'For established brands ready to outpace competitors and scale aggressively.',
                'ideal_for'       => 'Established brands where organic search is a major growth priority.',
                'is_most_popular' => false,
                'is_active'       => true,
                'sort_order'      => 3,
                'features'        => [
                    [
                        'title'       => 'Advanced competitor research',
                        'description' => 'In-depth analysis and strategy.',
                    ],
                    [
                        'title'       => 'SEO/AIO optimization',
                        'description' => 'For search and AI visibility.',
                    ],
                    [
                        'title'       => 'High-volume content production',
                        'description' => 'More blogs, pages and resources.',
                    ],
                    [
                        'title'       => 'Full technical SEO management',
                        'description' => 'Ongoing site health and performance.',
                    ],
                    [
                        'title'       => 'Reputation and PR link building',
                        'description' => 'Build brand authority.',
                    ],
                    [
                        'title'       => 'Monthly live check-ins',
                        'description' => 'Progress and strategy in real time.',
                    ],
                ],
            ],
        ];

        foreach ($packages as $package) {
            SeoPackage::updateOrCreate(['id' => $package['id']], $package);
        }
    }
}
