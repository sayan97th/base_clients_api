<?php

namespace Database\Seeders;

use App\Models\Coupon;
use Illuminate\Database\Seeder;

/**
 * Creates the HAPPYHOLIDAYS money back promotion.
 *
 * How it works for the client:
 *   1. The client checks out (links or content) with the code HAPPYHOLIDAYS.
 *      They pay the regular price, nothing is discounted at checkout.
 *   2. They are asked for the email address where the gift card should be
 *      sent and which gift card they want: Amazon, Visa or Target.
 *   3. The admins subscribed to the gift card notification are emailed each
 *      time a client submits those details, so the card can be sent promptly.
 *   4. The gift card is worth 10% of the order total, up to $1,000. Clients
 *      can order as much as they want, but one order never earns a gift
 *      card worth more than $1,000 (max_discount_amount on the coupon).
 *
 * Promotion dates: November 27 through December 18, 2026. Both days are
 * included. Days begin and end in the promotion timezone
 * (config app.promotion_timezone, PROMOTION_TIMEZONE in .env).
 *
 * Safe to run more than once and safe for production:
 *   php artisan db:seed --class=HolidayPromoCouponSeeder --force
 *
 * Re-running only refreshes the promo settings below. It never resets the
 * usage counter and never re-enables a coupon an admin turned off.
 *
 * The dates can be changed per environment without editing this file
 * (for example to test the promo before it starts):
 *   HOLIDAY_PROMO_STARTS_AT=2026-11-27   first day the code works
 *   HOLIDAY_PROMO_EXPIRES_AT=2026-12-18  last day the code works
 * Every value can also be changed later from Admin > Coupons.
 */
class HolidayPromoCouponSeeder extends Seeder
{
    public const CODE = 'HAPPYHOLIDAYS';

    public const REWARD_PERCENTAGE = 10.00;

    /** Highest gift card value one order can earn, whatever the order total. */
    public const MAX_REWARD_AMOUNT = 1000.00;

    public const FIRST_DAY = '2026-11-27';

    public const LAST_DAY = '2026-12-18';

    public function run(): void
    {
        $promo_settings = [
            'name'                    => 'Happy Holidays 10% Back',
            'description'             => 'Holiday promo running November 27 through December 18. Clients pay the regular price for links or content and receive a gift card (Amazon, Visa or Target) worth 10% of their order total, up to $1,000 per order.',
            'discount_type'           => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value'          => self::REWARD_PERCENTAGE,
            'applies_to'              => Coupon::APPLIES_TO_ALL,
            'product_types'           => null,
            'dr_tier_id'              => null,
            'minimum_purchase_amount' => null,
            'max_discount_amount'     => self::MAX_REWARD_AMOUNT,
            // Calendar days (Y-m-d). The Coupon model turns them into the start
            // of the first day and the end of the last day.
            'starts_at'               => $this->promoDate('HOLIDAY_PROMO_STARTS_AT', self::FIRST_DAY),
            'expires_at'              => $this->promoDate('HOLIDAY_PROMO_EXPIRES_AT', self::LAST_DAY),
            'usage_limit'             => null,
            'usage_per_user'          => null,
        ];

        $coupon = Coupon::withTrashed()->where('code', self::CODE)->first();

        if (! $coupon) {
            $coupon = Coupon::create(array_merge($promo_settings, [
                'code'       => self::CODE,
                'times_used' => 0,
                'is_active'  => true,
            ]));

            $this->reportWindow($coupon, 'created');

            return;
        }

        if ($coupon->trashed()) {
            $coupon->restore();
        }

        $coupon->update($promo_settings);
        $coupon->drTiers()->sync([]);

        $this->reportWindow($coupon, 'updated (usage and active status were kept)');
    }

    /**
     * Reads a Y-m-d date from the environment, falling back to the promo default.
     * Invalid values are rejected so a typo can never open the promo early or late.
     */
    private function promoDate(string $env_key, string $default): string
    {
        $value = trim((string) env($env_key, ''));

        if ($value === '') {
            return $default;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! strtotime($value)) {
            throw new \InvalidArgumentException("{$env_key} must be a date in Y-m-d format, got \"{$value}\".");
        }

        return $value;
    }

    private function reportWindow(Coupon $coupon, string $action): void
    {
        $this->command?->info(sprintf(
            '%s promo coupon %s. Valid from %s through %s (%s).',
            self::CODE,
            $action,
            $coupon->startDate(),
            $coupon->endDate(),
            Coupon::promotionTimezone(),
        ));
    }
}
