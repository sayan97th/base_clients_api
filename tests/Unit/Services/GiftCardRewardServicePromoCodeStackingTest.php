<?php

namespace Tests\Unit\Services;

use App\Services\GiftCardRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Discount\InteractsWithBulkDiscounts;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * GiftCardRewardService::allowsBulkDiscountWith decides whether the bulk
 * discount stays on an order that uses a money back promo code. The cart
 * checkout and the pay later checkout both rely on it.
 */
class GiftCardRewardServicePromoCodeStackingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithBulkDiscounts;
    use InteractsWithGiftCardRewards;

    private GiftCardRewardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GiftCardRewardService::class);
    }

    public function test_bulk_discount_is_kept_without_a_money_back_promo_code(): void
    {
        $this->makeBulkDiscount();

        $this->assertTrue($this->service->allowsBulkDiscountWith(null, 'link_building'));
    }

    public function test_bulk_discount_is_kept_without_a_promo_code_even_with_no_discount_records(): void
    {
        $this->assertTrue($this->service->allowsBulkDiscountWith(null, 'link_building'));
    }

    public function test_money_back_promo_code_replaces_the_bulk_discount_by_default(): void
    {
        $this->makeBulkDiscount();

        $this->assertFalse($this->service->allowsBulkDiscountWith($this->makeRewardCoupon(), 'link_building'));
    }

    public function test_money_back_promo_code_keeps_the_bulk_discount_when_stacking_is_allowed(): void
    {
        $this->makeStackableBulkDiscount();

        $this->assertTrue($this->service->allowsBulkDiscountWith($this->makeRewardCoupon(), 'link_building'));
    }

    public function test_the_decision_follows_the_product_type(): void
    {
        $this->makeStackableBulkDiscount();
        $this->makeBulkDiscount(['name' => 'Content bulk', 'applies_to' => 'new_content']);
        $coupon = $this->makeRewardCoupon();

        $this->assertTrue($this->service->allowsBulkDiscountWith($coupon, 'link_building'));
        $this->assertFalse($this->service->allowsBulkDiscountWith($coupon, 'new_content'));
    }
}
