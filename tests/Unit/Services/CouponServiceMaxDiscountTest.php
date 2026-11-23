<?php

namespace Tests\Unit\Services;

use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * CouponService is where every checkout path (card, pay later, promo code
 * validation) calculates the discount and the gift card, so the per order
 * limit (max_discount_amount) is enforced here once for all of them.
 */
class CouponServiceMaxDiscountTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CouponService();
    }

    // ── calculateRewardAmount (gift card) ────────────────────────────────────

    /**
     * The exact cases from the client request: order as much as you want,
     * the gift card never goes over $1,000.
     *
     * @return array<string, array{float, float}>
     */
    public static function holidayOrders(): array
    {
        return [
            'small order'                 => [800.0, 80.0],
            'order just below the limit'  => [9999.9, 999.99],
            'order exactly at the limit'  => [10000.0, 1000.0],
            'order just above the limit'  => [10000.1, 1000.0],
            '$20,000 order'               => [20000.0, 1000.0],
            '$30,000 order'               => [30000.0, 1000.0],
            '$50,000 order'               => [50000.0, 1000.0],
            '$1,000,000 order'            => [1000000.0, 1000.0],
        ];
    }

    #[DataProvider('holidayOrders')]
    public function test_holiday_gift_card_never_exceeds_one_thousand_dollars(float $order_total, float $expected_reward): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->assertSame($expected_reward, $this->service->calculateRewardAmount($coupon, $order_total));
    }

    public function test_uncapped_reward_ignores_the_limit(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->assertSame(5000.0, $this->service->calculateUncappedRewardAmount($coupon, 50000.0));
        $this->assertSame(1000.0, $this->service->calculateRewardAmount($coupon, 50000.0));
    }

    public function test_gift_card_without_a_limit_grows_with_the_order(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => null]);

        $this->assertSame(5000.0, $this->service->calculateRewardAmount($coupon, 50000.0));
    }

    public function test_negative_totals_never_produce_a_reward(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->assertSame(0.0, $this->service->calculateRewardAmount($coupon, -500.0));
    }

    public function test_reward_helpers_return_zero_for_regular_coupons(): void
    {
        $coupon = $this->makeRegularCoupon(['max_discount_amount' => 1000.0]);

        $this->assertSame(0.0, $this->service->calculateRewardAmount($coupon, 50000.0));
        $this->assertSame(0.0, $this->service->calculateUncappedRewardAmount($coupon, 50000.0));
    }

    // ── validateAndCalculate ─────────────────────────────────────────────────

    public function test_validation_returns_the_capped_reward_and_no_discount(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $result = $this->service->validateAndCalculate($coupon, 50000.0, 1);

        $this->assertTrue($result['valid']);
        $this->assertSame(0.0, $result['discount_amount']);
        $this->assertSame(1000.0, $result['reward_amount']);
    }

    public function test_percentage_discount_is_capped(): void
    {
        $coupon = $this->makeRegularCoupon(['discount_value' => 20.0, 'max_discount_amount' => 300.0]);

        $this->assertEquals(300.0, $this->service->validateAndCalculate($coupon, 5000.0, 1)['discount_amount']);
        $this->assertEquals(200.0, $this->service->validateAndCalculate($coupon, 1000.0, 1)['discount_amount']);
    }

    public function test_percentage_limit_applies_to_the_restricted_product_amount(): void
    {
        $coupon = $this->makeRegularCoupon([
            'discount_value'      => 50.0,
            'product_types'       => ['link_building'],
            'max_discount_amount' => 400.0,
        ]);

        // 50% of the $2,000 link building subtotal is $1,000, limited to $400.
        $result = $this->service->validateAndCalculate(
            $coupon,
            5000.0,
            1,
            [],
            [],
            ['link_building', 'new_content'],
            ['link_building' => 2000.0, 'new_content' => 3000.0],
        );

        $this->assertEquals(400.0, $result['discount_amount']);
    }

    public function test_fixed_amount_discount_ignores_a_stored_limit(): void
    {
        $coupon = $this->makeRegularCoupon([
            'discount_type'       => Coupon::DISCOUNT_TYPE_FIXED_AMOUNT,
            'discount_value'      => 500.0,
            'max_discount_amount' => 100.0,
        ]);

        $this->assertEquals(500.0, $this->service->validateAndCalculate($coupon, 2000.0, 1)['discount_amount']);
    }

    public function test_the_limit_does_not_change_the_other_validation_rules(): void
    {
        $coupon = $this->makeRewardCoupon([
            'max_discount_amount' => 1000.0,
            'expires_at'          => now()->subDay(),
        ]);

        $result = $this->service->validateAndCalculate($coupon, 50000.0, 1);

        $this->assertFalse($result['valid']);
        $this->assertSame('This coupon has expired.', $result['message']);
    }
}
