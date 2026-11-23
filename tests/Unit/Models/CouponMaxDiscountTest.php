<?php

namespace Tests\Unit\Models;

use App\Models\Coupon;
use Tests\TestCase;

/**
 * Coupon helpers behind the per order limit (max_discount_amount):
 * supportsMaxDiscount, maxDiscountAmount and applyMaxDiscount.
 *
 * No database needed, the coupons are only built in memory.
 */
class CouponMaxDiscountTest extends TestCase
{
    private function coupon(string $discount_type, mixed $max_discount_amount): Coupon
    {
        return new Coupon([
            'discount_type'       => $discount_type,
            'discount_value'      => 10.0,
            'max_discount_amount' => $max_discount_amount,
        ]);
    }

    // ── supportsMaxDiscount ──────────────────────────────────────────────────

    public function test_gift_card_and_percentage_coupons_support_a_limit(): void
    {
        $this->assertTrue(Coupon::supportsMaxDiscount(Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD));
        $this->assertTrue(Coupon::supportsMaxDiscount(Coupon::DISCOUNT_TYPE_PERCENTAGE));
    }

    public function test_fixed_amount_and_unknown_types_do_not_support_a_limit(): void
    {
        $this->assertFalse(Coupon::supportsMaxDiscount(Coupon::DISCOUNT_TYPE_FIXED_AMOUNT));
        $this->assertFalse(Coupon::supportsMaxDiscount('fixed'));
        $this->assertFalse(Coupon::supportsMaxDiscount(null));
    }

    // ── maxDiscountAmount ────────────────────────────────────────────────────

    public function test_it_returns_the_limit_as_a_float(): void
    {
        $this->assertSame(1000.0, $this->coupon(Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD, 1000)->maxDiscountAmount());
        $this->assertSame(250.5, $this->coupon(Coupon::DISCOUNT_TYPE_PERCENTAGE, '250.50')->maxDiscountAmount());
    }

    public function test_it_returns_null_without_a_usable_limit(): void
    {
        $this->assertNull($this->coupon(Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD, null)->maxDiscountAmount());
        $this->assertNull($this->coupon(Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD, 0)->maxDiscountAmount());
        $this->assertNull($this->coupon(Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD, -5)->maxDiscountAmount());
    }

    public function test_a_limit_stored_on_a_fixed_amount_coupon_is_ignored(): void
    {
        $this->assertNull($this->coupon(Coupon::DISCOUNT_TYPE_FIXED_AMOUNT, 100)->maxDiscountAmount());
    }

    // ── applyMaxDiscount ─────────────────────────────────────────────────────

    public function test_amounts_above_the_limit_are_lowered_to_the_limit(): void
    {
        $coupon = $this->coupon(Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD, 1000);

        $this->assertSame(1000.0, $coupon->applyMaxDiscount(2000.0));
        $this->assertSame(1000.0, $coupon->applyMaxDiscount(5000.0));
        $this->assertSame(1000.0, $coupon->applyMaxDiscount(1000.01));
    }

    public function test_amounts_at_or_below_the_limit_are_kept(): void
    {
        $coupon = $this->coupon(Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD, 1000);

        $this->assertSame(1000.0, $coupon->applyMaxDiscount(1000.0));
        $this->assertSame(999.99, $coupon->applyMaxDiscount(999.99));
        $this->assertSame(0.0, $coupon->applyMaxDiscount(0.0));
    }

    public function test_amounts_are_untouched_when_there_is_no_limit(): void
    {
        $this->assertSame(5000.0, $this->coupon(Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD, null)->applyMaxDiscount(5000.0));
        $this->assertSame(500.0, $this->coupon(Coupon::DISCOUNT_TYPE_FIXED_AMOUNT, 100)->applyMaxDiscount(500.0));
    }

    public function test_the_limit_is_cast_to_a_float_when_read_from_the_model(): void
    {
        $this->assertIsFloat($this->coupon(Coupon::DISCOUNT_TYPE_PERCENTAGE, '300')->max_discount_amount);
    }
}
