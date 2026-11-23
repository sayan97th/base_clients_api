<?php

use Database\Seeders\HolidayPromoCouponSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The client asked for the HAPPYHOLIDAYS gift card to be capped at $1,000,
 * whatever the order total is. This applies the cap to the coupon that is
 * already live without re-running the seeder, so no other setting an admin
 * may have changed (dates, status, usage) is touched.
 *
 * Only a coupon without a cap is updated, so a different value set from
 * Admin > Coupons is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('coupons')
            ->where('code', HolidayPromoCouponSeeder::CODE)
            ->where('discount_type', 'gift_card_reward')
            ->whereNull('max_discount_amount')
            ->update([
                'max_discount_amount' => HolidayPromoCouponSeeder::MAX_REWARD_AMOUNT,
                'updated_at'          => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('coupons')
            ->where('code', HolidayPromoCouponSeeder::CODE)
            ->where('max_discount_amount', HolidayPromoCouponSeeder::MAX_REWARD_AMOUNT)
            ->update(['max_discount_amount' => null]);
    }
};
