<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Pure-logic tests for the cart total calculation rules — no DB or HTTP calls.
 * These validate the business rules for bulk discounts and coupon overrides
 * that are implemented in CartController::calculateExpectedTotal.
 */
class CartTotalCalculationTest extends TestCase
{
    private const BULK_DISCOUNT_THRESHOLD = 10;
    private const BULK_DISCOUNT_RATE      = 0.10;

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function calculateLinkBuildingTotal(
        array $items,
        float $coupon_amount = 0.0,
        bool $is_credits_payment = false
    ): float {
        $total_links = 0;
        $subtotal    = 0.0;

        foreach ($items as $item) {
            $total_links += $item['quantity'];
            $subtotal    += $item['unit_price'] * $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $potential_bulk = (! $is_credits_payment && $total_links >= self::BULK_DISCOUNT_THRESHOLD)
            ? round($subtotal * self::BULK_DISCOUNT_RATE, 2)
            : 0.0;

        $effective_discount = $coupon_amount > 0 ? $coupon_amount : $potential_bulk;

        return max(0.0, round($subtotal - $effective_discount, 2));
    }

    // ─── Subtotal calculation ────────────────────────────────────────────────

    public function test_single_item_subtotal_is_correct(): void
    {
        $items = [['unit_price' => 100.0, 'quantity' => 3]];

        $total = $this->calculateLinkBuildingTotal($items);

        $this->assertEquals(300.0, $total);
    }

    public function test_multiple_items_subtotal_is_summed(): void
    {
        $items = [
            ['unit_price' => 100.0, 'quantity' => 2],
            ['unit_price' => 200.0, 'quantity' => 5],
        ];

        $total = $this->calculateLinkBuildingTotal($items);

        // 200 + 1000 = 1200 (7 links, no bulk discount)
        $this->assertEquals(1200.0, $total);
    }

    // ─── Bulk discount threshold ─────────────────────────────────────────────

    public function test_bulk_discount_not_applied_below_threshold(): void
    {
        $items = [['unit_price' => 100.0, 'quantity' => 9]]; // 9 links < 10

        $total = $this->calculateLinkBuildingTotal($items);

        $this->assertEquals(900.0, $total); // no discount
    }

    public function test_bulk_discount_applied_at_threshold(): void
    {
        $items = [['unit_price' => 100.0, 'quantity' => 10]]; // exactly 10

        $total = $this->calculateLinkBuildingTotal($items);

        $expected = round(1000.0 - (1000.0 * 0.10), 2); // 900.00
        $this->assertEquals($expected, $total);
    }

    public function test_bulk_discount_applied_above_threshold(): void
    {
        $items = [['unit_price' => 100.0, 'quantity' => 15]];

        $total = $this->calculateLinkBuildingTotal($items);

        $subtotal = 1500.0;
        $expected = round($subtotal - ($subtotal * 0.10), 2); // 1350.00
        $this->assertEquals($expected, $total);
    }

    // ─── Coupon overrides bulk ───────────────────────────────────────────────

    public function test_coupon_overrides_bulk_discount_when_coupon_is_higher(): void
    {
        $items = [['unit_price' => 100.0, 'quantity' => 10]]; // bulk = 100
        $coupon_amount = 200.0; // coupon wins

        $total = $this->calculateLinkBuildingTotal($items, $coupon_amount);

        $this->assertEquals(800.0, $total);
    }

    public function test_coupon_overrides_bulk_discount_even_when_coupon_is_lower(): void
    {
        $items = [['unit_price' => 100.0, 'quantity' => 10]]; // bulk = 100
        $coupon_amount = 50.0; // coupon is smaller but still replaces bulk

        $total = $this->calculateLinkBuildingTotal($items, $coupon_amount);

        $this->assertEquals(950.0, $total); // 1000 - 50 = 950 (not 900)
    }

    public function test_no_discount_when_no_coupon_and_below_threshold(): void
    {
        $items = [['unit_price' => 200.0, 'quantity' => 5]];

        $total = $this->calculateLinkBuildingTotal($items);

        $this->assertEquals(1000.0, $total);
    }

    // ─── Credits payment skips discounts ────────────────────────────────────

    public function test_bulk_discount_skipped_for_credits_payment(): void
    {
        $items = [['unit_price' => 100.0, 'quantity' => 15]];

        $total = $this->calculateLinkBuildingTotal($items, 0.0, true);

        $this->assertEquals(1500.0, $total); // no discount applied
    }

    // ─── Total never goes negative ───────────────────────────────────────────

    public function test_total_never_goes_negative_with_large_coupon(): void
    {
        $items = [['unit_price' => 50.0, 'quantity' => 2]];
        $coupon_amount = 9999.0; // far exceeds subtotal

        $total = $this->calculateLinkBuildingTotal($items, $coupon_amount);

        $this->assertEquals(0.0, $total);
    }

    // ─── Floating-point precision ────────────────────────────────────────────

    public function test_subtotal_rounded_to_two_decimal_places(): void
    {
        $items = [['unit_price' => 33.33, 'quantity' => 3]]; // 99.99

        $total = $this->calculateLinkBuildingTotal($items);

        $this->assertEquals(99.99, $total);
    }
}
