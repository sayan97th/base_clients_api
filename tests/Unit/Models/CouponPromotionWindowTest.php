<?php

namespace Tests\Unit\Models;

use App\Models\Coupon;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Coupon::wasRunningAt and Coupon::isRunning decide whether a promo code
 * (for example HAPPYHOLIDAYS) is inside its valid dates.
 */
class CouponPromotionWindowTest extends TestCase
{
    private function makeCoupon(array $attributes = []): Coupon
    {
        return new Coupon(array_merge([
            'code'          => 'HAPPYHOLIDAYS',
            'discount_type' => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'is_active'     => true,
            'starts_at'     => '2026-12-01 00:00:00',
            'expires_at'    => '2026-12-31 23:59:59',
        ], $attributes));
    }

    public function test_moments_inside_the_dates_are_valid(): void
    {
        $coupon = $this->makeCoupon();

        $this->assertTrue($coupon->wasRunningAt(Carbon::parse('2026-12-01 00:00:00')));
        $this->assertTrue($coupon->wasRunningAt(Carbon::parse('2026-12-15 12:00:00')));
        $this->assertTrue($coupon->wasRunningAt(Carbon::parse('2026-12-31 23:59:59')));
    }

    public function test_moments_outside_the_dates_are_not_valid(): void
    {
        $coupon = $this->makeCoupon();

        $this->assertFalse($coupon->wasRunningAt(Carbon::parse('2026-11-30 23:59:59')));
        $this->assertFalse($coupon->wasRunningAt(Carbon::parse('2027-01-01 00:00:00')));
    }

    public function test_open_ended_promos_are_always_inside_their_dates(): void
    {
        $coupon = $this->makeCoupon(['starts_at' => null, 'expires_at' => null]);

        $this->assertTrue($coupon->wasRunningAt(Carbon::parse('2020-01-01')));
        $this->assertTrue($coupon->wasRunningAt(Carbon::parse('2040-01-01')));
    }

    public function test_is_running_uses_the_current_time(): void
    {
        $coupon = $this->makeCoupon();

        $this->travelTo('2026-12-10 09:00:00');
        $this->assertTrue($coupon->isRunning());

        $this->travelTo('2027-01-02 09:00:00');
        $this->assertFalse($coupon->isRunning());
    }

    public function test_is_running_also_requires_an_active_coupon(): void
    {
        $this->travelTo('2026-12-10 09:00:00');

        $this->assertFalse($this->makeCoupon(['is_active' => false])->isRunning());
    }
}
