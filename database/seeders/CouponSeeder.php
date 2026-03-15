<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        $coupons = [
            [
                'code'                    => 'TEST-WELCOME10',
                'name'                    => 'Test Welcome Discount',
                'description'             => 'A 10% welcome discount for testing purposes.',
                'discount_type'           => 'percentage',
                'discount_value'          => 10.00,
                'applies_to'              => 'all',
                'dr_tier_id'              => null,
                'minimum_purchase_amount' => null,
                'starts_at'               => now(),
                'expires_at'              => now()->addYear(),
                'usage_limit'             => null,
                'usage_per_user'          => null,
                'times_used'              => 0,
                'is_active'               => true,
            ],
            [
                'code'                    => 'TEST-SAVE50',
                'name'                    => 'Test Flat $50 Off',
                'description'             => 'Save $50 on any order — testing fixed amount discount.',
                'discount_type'           => 'fixed_amount',
                'discount_value'          => 50.00,
                'applies_to'              => 'all',
                'dr_tier_id'              => null,
                'minimum_purchase_amount' => null,
                'starts_at'               => now(),
                'expires_at'              => now()->addYear(),
                'usage_limit'             => null,
                'usage_per_user'          => null,
                'times_used'              => 0,
                'is_active'               => true,
            ],
            [
                'code'                    => 'TEST-BOOST20',
                'name'                    => 'Test Power Boost',
                'description'             => '20% off for orders that meet the minimum spend — testing minimum purchase threshold.',
                'discount_type'           => 'percentage',
                'discount_value'          => 20.00,
                'applies_to'              => 'minimum_purchase',
                'dr_tier_id'              => null,
                'minimum_purchase_amount' => 500.00,
                'starts_at'               => now(),
                'expires_at'              => now()->addYear(),
                'usage_limit'             => null,
                'usage_per_user'          => null,
                'times_used'              => 0,
                'is_active'               => true,
            ],
            [
                'code'                    => 'TEST-TIER-DR50',
                'name'                    => 'Test DR 50+ Perk',
                'description'             => '15% off on DR 50+ links — testing tier-specific discount.',
                'discount_type'           => 'percentage',
                'discount_value'          => 15.00,
                'applies_to'              => 'specific_product',
                'dr_tier_id'              => 'dr_50',
                'minimum_purchase_amount' => null,
                'starts_at'               => now(),
                'expires_at'              => now()->addYear(),
                'usage_limit'             => null,
                'usage_per_user'          => null,
                'times_used'              => 0,
                'is_active'               => true,
            ],
            [
                'code'                    => 'TEST-LAUNCH100',
                'name'                    => 'Test Launch Special',
                'description'             => '$100 off flat — testing high-value fixed discount.',
                'discount_type'           => 'fixed_amount',
                'discount_value'          => 100.00,
                'applies_to'              => 'minimum_purchase',
                'dr_tier_id'              => null,
                'minimum_purchase_amount' => 800.00,
                'starts_at'               => now(),
                'expires_at'              => now()->addYear(),
                'usage_limit'             => 50,
                'usage_per_user'          => 1,
                'times_used'              => 0,
                'is_active'               => true,
            ],
            [
                'code'                    => 'TEST-ONCE5',
                'name'                    => 'Test One-Time Treat',
                'description'             => '5% off, single use per user — testing per-user usage limit.',
                'discount_type'           => 'percentage',
                'discount_value'          => 5.00,
                'applies_to'              => 'all',
                'dr_tier_id'              => null,
                'minimum_purchase_amount' => null,
                'starts_at'               => now(),
                'expires_at'              => now()->addYear(),
                'usage_limit'             => null,
                'usage_per_user'          => 1,
                'times_used'              => 0,
                'is_active'               => true,
            ],
        ];

        foreach ($coupons as $coupon_data) {
            Coupon::updateOrCreate(
                ['code' => $coupon_data['code']],
                $coupon_data
            );
        }
    }
}
