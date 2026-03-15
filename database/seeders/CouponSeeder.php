<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        // Rename legacy codes that contained hyphens (invalid per current validation)
        $legacy_code_map = [
            'TEST-WELCOME10' => 'TESTWELCOME10',
            'TEST-SAVE50'    => 'TESTSAVE50',
            'TEST-BOOST20'   => 'TESTBOOST20',
            'TEST-TIER-DR50' => 'TESTTIERDR50',
            'TEST-LAUNCH100' => 'TESTLAUNCH100',
            'TEST-ONCE5'     => 'TESTONCE5',
        ];

        foreach ($legacy_code_map as $old_code => $new_code) {
            Coupon::where('code', $old_code)->update(['code' => $new_code]);
        }

        $coupons = [
            [
                'code'                    => 'TESTWELCOME10',
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
                'code'                    => 'TESTSAVE50',
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
                'code'                    => 'TESTBOOST20',
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
                'code'                    => 'TESTTIERDR50',
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
                'code'                    => 'TESTLAUNCH100',
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
                'code'                    => 'TESTONCE5',
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
