<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Seeder;

/**
 * Creates the HAPPYHOLIDAYS money back promotion: clients who check out with
 * this code pay the regular price and receive a gift card (Amazon, Visa or
 * Target) worth 10% of their order total.
 *
 * Safe to run more than once and safe for production:
 *   php artisan db:seed --class=HolidayPromoCouponSeeder --force
 *
 * Re-running only refreshes the promo settings below. It never resets the
 * usage counter and never re-enables a coupon an admin turned off.
 *
 * The promo dates can be set per environment without editing this file:
 *   HOLIDAY_PROMO_STARTS_AT=2026-11-20
 *   HOLIDAY_PROMO_EXPIRES_AT=2027-01-01
 * The promo stays live until the start of the expiry date, so 2027-01-01
 * means the code works through December 31. Every value can also be changed
 * later from Admin > Coupons.
 */
class HolidayPromoCouponSeeder extends Seeder
{
    public const CODE = 'HAPPYHOLIDAYS';

    private const REWARD_PERCENTAGE = 10.00;

    private const DEFAULT_EXPIRES_AT = '2027-01-01';

    public function run(): void
    {
        $starts_at  = env('HOLIDAY_PROMO_STARTS_AT');
        $expires_at = env('HOLIDAY_PROMO_EXPIRES_AT', self::DEFAULT_EXPIRES_AT);

        $promo_settings = [
            'name'                    => 'Happy Holidays 10% Back',
            'description'             => 'Holiday promo. Clients pay the regular price and receive a gift card (Amazon, Visa or Target) worth 10% of their order total.',
            'discount_type'           => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value'          => self::REWARD_PERCENTAGE,
            'applies_to'              => 'all',
            'product_types'           => null,
            'dr_tier_id'              => null,
            'minimum_purchase_amount' => null,
            'starts_at'               => $starts_at ?: null,
            'expires_at'              => $expires_at ?: null,
            'usage_limit'             => null,
            'usage_per_user'          => null,
        ];

        $coupon = Coupon::withTrashed()->where('code', self::CODE)->first();

        if (! $coupon) {
            Coupon::create(array_merge($promo_settings, [
                'code'       => self::CODE,
                'times_used' => 0,
                'is_active'  => true,
            ]));

            $this->command?->info(self::CODE . ' promo coupon created.');

            return;
        }

        if ($coupon->trashed()) {
            $coupon->restore();
        }

        $coupon->update($promo_settings);
        $coupon->drTiers()->sync([]);

        $this->command?->info(self::CODE . ' promo coupon updated (usage and active status were kept).');
    }
}
