<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\NewsPost;
use Illuminate\Database\Seeder;

class NewsPostSeeder extends Seeder
{
    public function run(): void
    {
        $coupon_save14 = Coupon::where('code', 'TESTWELCOME10')->first();

        $posts = [
            // ── Featured promo (linked to coupon) ─────────────────────────────
            [
                'type'           => 'promo',
                'status'         => 'active',
                'subtitle'       => '10% off your next order — this month only',
                'description'    => 'Take advantage of our limited-time welcome promotion and save 10% on any link building order placed this month. No minimum spend required.',
                'discount_value' => '10',
                'discount_label' => 'All Services',
                'coupon_id'      => $coupon_save14?->id,
                'starts_at'      => now()->startOfMonth()->toDateString(),
                'ends_at'        => now()->endOfMonth()->toDateString(),
                'cta_text'       => 'Claim Offer',
                'cta_url'        => 'https://yourdomain.com/promo',
                'tags'           => ['promo', 'discount', 'monthly'],
                'is_featured'    => true,
                'sort_order'     => 0,
            ],

            // ── Blog post ──────────────────────────────────────────────────────
            [
                'type'           => 'blog_post',
                'status'         => 'active',
                'subtitle'       => 'Tips to help you get found in local search',
                'description'    => 'Local SEO is one of the fastest growing areas of digital marketing. Learn how to optimize your Google Business Profile, build local citations, and earn links from geo-relevant sites to dominate your local market.',
                'discount_value' => null,
                'discount_label' => null,
                'coupon_id'      => null,
                'starts_at'      => null,
                'ends_at'        => null,
                'cta_text'       => 'Read More',
                'cta_url'        => 'https://yourdomain.com/blog/local-seo-tips',
                'tags'           => ['seo', 'local', 'blog'],
                'is_featured'    => false,
                'sort_order'     => 1,
            ],

            // ── SEO tip ───────────────────────────────────────────────────────
            [
                'type'           => 'tip',
                'status'         => 'active',
                'subtitle'       => 'AI tools don\'t crawl your meta descriptions',
                'description'    => null,
                'discount_value' => null,
                'discount_label' => null,
                'coupon_id'      => null,
                'starts_at'      => null,
                'ends_at'        => null,
                'cta_text'       => null,
                'cta_url'        => null,
                'tags'           => ['seo', 'tips', 'ai'],
                'is_featured'    => false,
                'sort_order'     => 2,
            ],

            // ── News update ───────────────────────────────────────────────────
            [
                'type'           => 'news',
                'status'         => 'active',
                'subtitle'       => 'Our new DR 70+ tier is now available for all clients',
                'description'    => 'We have added a new premium DR 70+ link building tier to our catalog. These high-authority placements are ideal for competitive niches and national campaigns. Log in to place your first order.',
                'discount_value' => null,
                'discount_label' => null,
                'coupon_id'      => null,
                'starts_at'      => null,
                'ends_at'        => null,
                'cta_text'       => 'View Pricing',
                'cta_url'        => 'https://yourdomain.com/services',
                'tags'           => ['news', 'link-building', 'dr70'],
                'is_featured'    => false,
                'sort_order'     => 3,
            ],

            // ── Second tip ────────────────────────────────────────────────────
            [
                'type'           => 'tip',
                'status'         => 'active',
                'subtitle'       => 'Internal links pass authority — don\'t ignore them',
                'description'    => null,
                'discount_value' => null,
                'discount_label' => null,
                'coupon_id'      => null,
                'starts_at'      => null,
                'ends_at'        => null,
                'cta_text'       => null,
                'cta_url'        => null,
                'tags'           => ['seo', 'tips', 'internal-links'],
                'is_featured'    => false,
                'sort_order'     => 4,
            ],

            // ── Draft post (should NOT appear on client feed) ─────────────────
            [
                'type'           => 'blog_post',
                'status'         => 'draft',
                'subtitle'       => 'Coming soon — link velocity and Google\'s crawl budget',
                'description'    => 'This post is still being written. It should not appear in the client news feed.',
                'discount_value' => null,
                'discount_label' => null,
                'coupon_id'      => null,
                'starts_at'      => null,
                'ends_at'        => null,
                'cta_text'       => null,
                'cta_url'        => null,
                'tags'           => ['seo', 'crawl-budget'],
                'is_featured'    => false,
                'sort_order'     => 5,
            ],
        ];

        $titles = [
            'Monthly Welcome Promo',
            'Local SEO Tips for 2026',
            'SEO Tip: Meta Descriptions & AI',
            'New DR 70+ Tier Now Available',
            'SEO Tip: Internal Links',
            'Draft: Link Velocity & Crawl Budget',
        ];

        foreach ($posts as $index => $post_data) {
            NewsPost::updateOrCreate(
                ['title' => $titles[$index]],
                array_merge($post_data, ['title' => $titles[$index]])
            );
        }
    }
}
