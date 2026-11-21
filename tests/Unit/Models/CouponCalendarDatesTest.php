<?php

namespace Tests\Unit\Models;

use App\Models\Coupon;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Coupon start and expiry dates are calendar days in the promotion timezone,
 * and both days are included in the promotion.
 */
class CouponCalendarDatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.promotion_timezone' => 'America/Denver']);
    }

    public function test_a_start_date_begins_at_midnight_in_the_promotion_timezone(): void
    {
        $coupon = new Coupon(['starts_at' => '2026-11-27']);

        $this->assertSame('2026-11-27 07:00:00', $coupon->getAttributes()['starts_at']);
        $this->assertSame('2026-11-27', $coupon->startDate());
    }

    public function test_an_expiry_date_lasts_until_the_end_of_that_day(): void
    {
        $coupon = new Coupon(['expires_at' => '2026-12-18']);

        $this->assertSame('2026-12-19 06:59:59', $coupon->getAttributes()['expires_at']);
        $this->assertSame('2026-12-18', $coupon->endDate());
    }

    public function test_the_whole_expiry_day_is_inside_the_promotion(): void
    {
        $coupon = new Coupon(['starts_at' => '2026-11-27', 'expires_at' => '2026-12-18']);

        $this->assertTrue($coupon->wasRunningAt(Carbon::parse('2026-12-18 23:59:58', 'America/Denver')));
        $this->assertFalse($coupon->wasRunningAt(Carbon::parse('2026-12-19 00:00:00', 'America/Denver')));
        $this->assertFalse($coupon->wasRunningAt(Carbon::parse('2026-11-26 23:59:59', 'America/Denver')));
    }

    public function test_a_single_day_promotion_is_valid_for_that_whole_day(): void
    {
        $coupon = new Coupon(['starts_at' => '2026-12-01', 'expires_at' => '2026-12-01']);

        $this->assertTrue($coupon->wasRunningAt(Carbon::parse('2026-12-01 00:00:00', 'America/Denver')));
        $this->assertTrue($coupon->wasRunningAt(Carbon::parse('2026-12-01 23:59:58', 'America/Denver')));
    }

    public function test_full_date_times_are_kept_as_exact_moments(): void
    {
        $coupon = new Coupon([
            'starts_at'  => '2026-12-01 10:15:00',
            'expires_at' => Carbon::parse('2026-12-31 17:00:00', 'America/Denver'),
        ]);

        $this->assertSame('2026-12-01 10:15:00', $coupon->getAttributes()['starts_at']);
        $this->assertSame('2027-01-01 00:00:00', $coupon->getAttributes()['expires_at']);
    }

    public function test_dates_with_an_offset_are_converted_to_utc(): void
    {
        $coupon = new Coupon(['expires_at' => '2026-12-18T23:00:00-07:00']);

        $this->assertSame('2026-12-19 06:00:00', $coupon->getAttributes()['expires_at']);
    }

    public function test_empty_dates_mean_no_limit(): void
    {
        $coupon = new Coupon(['starts_at' => '', 'expires_at' => null]);

        $this->assertNull($coupon->starts_at);
        $this->assertNull($coupon->expires_at);
        $this->assertNull($coupon->startDate());
        $this->assertNull($coupon->endDate());
    }
}
