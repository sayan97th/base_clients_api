<?php

namespace Tests\Feature\Coupon\MaxDiscount;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * POST /api/coupons/validate with a coupon that has a per order limit. The
 * checkout uses this response to show the gift card estimate and the
 * "up to $1,000" copy, so it must carry the limit and the capped reward.
 */
class CouponValidationMaxDiscountTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client');
    }

    private function validateCode(string $code, float $order_amount, array $product_type_amounts = []): TestResponse
    {
        $product_type_amounts = $product_type_amounts ?: ['link_building' => $order_amount];

        return $this->actingAs($this->client, 'api')->postJson('/api/coupons/validate', [
            'code'                 => $code,
            'order_amount'         => $order_amount,
            'cart_product_types'   => array_keys($product_type_amounts),
            'product_type_amounts' => $product_type_amounts,
        ])->assertOk();
    }

    // ── Gift card coupons ────────────────────────────────────────────────────

    public function test_a_large_order_gets_the_capped_gift_card(): void
    {
        $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->validateCode('happyholidays', 50000.0)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('is_gift_card_reward', true)
            ->assertJsonPath('reward_percentage', 10)
            ->assertJsonPath('max_discount_amount', 1000)
            ->assertJsonPath('discount_amount', 0)
            ->assertJsonPath('reward_amount', 1000)
            ->assertJsonPath('is_reward_capped', true)
            ->assertJsonPath('message', 'Promo code applied. You will receive one gift card for this whole order, worth 10% of your order total, up to $1,000.');
    }

    public function test_an_order_below_the_limit_gets_the_full_percentage(): void
    {
        $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->validateCode('HAPPYHOLIDAYS', 4000.0)
            ->assertJsonPath('reward_amount', 400)
            ->assertJsonPath('is_reward_capped', false)
            ->assertJsonPath('max_discount_amount', 1000);
    }

    public function test_an_order_landing_exactly_on_the_limit_is_not_flagged_as_capped(): void
    {
        $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->validateCode('HAPPYHOLIDAYS', 10000.0)
            ->assertJsonPath('reward_amount', 1000)
            ->assertJsonPath('is_reward_capped', false);
    }

    public function test_the_limit_applies_to_the_whole_cart_with_several_products(): void
    {
        $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->validateCode('HAPPYHOLIDAYS', 25000.0, [
            'link_building'        => 12000.0,
            'new_content'          => 8000.0,
            'content_optimization' => 5000.0,
        ])
            ->assertJsonPath('reward_amount', 1000)
            ->assertJsonPath('is_reward_capped', true);
    }

    public function test_the_message_shows_cents_when_the_limit_has_them(): void
    {
        $this->makeRewardCoupon(['max_discount_amount' => 1250.5]);

        $this->validateCode('HAPPYHOLIDAYS', 50000.0)
            ->assertJsonPath('reward_amount', 1250.5)
            ->assertJsonPath('message', 'Promo code applied. You will receive one gift card for this whole order, worth 10% of your order total, up to $1,250.50.');
    }

    public function test_a_gift_card_coupon_without_a_limit_keeps_the_original_message(): void
    {
        $this->makeRewardCoupon();

        $this->validateCode('HAPPYHOLIDAYS', 50000.0)
            ->assertJsonPath('max_discount_amount', null)
            ->assertJsonPath('reward_amount', 5000)
            ->assertJsonPath('is_reward_capped', false)
            ->assertJsonPath('message', 'Promo code applied. You will receive one gift card for this whole order, worth 10% of your order total.');
    }

    // ── Percentage and fixed amount coupons ──────────────────────────────────

    public function test_a_percentage_coupon_returns_the_capped_discount(): void
    {
        $this->makeRegularCoupon(['code' => 'SAVE20', 'discount_value' => 20.0, 'max_discount_amount' => 300.0]);

        $this->validateCode('SAVE20', 5000.0)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('discount_amount', 300)
            ->assertJsonPath('max_discount_amount', 300)
            ->assertJsonPath('is_reward_capped', false)
            ->assertJsonPath('message', 'Coupon applied successfully.');
    }

    public function test_a_fixed_amount_coupon_reports_no_limit(): void
    {
        $this->makeRegularCoupon([
            'code'                => 'FLAT100',
            'discount_type'       => 'fixed_amount',
            'discount_value'      => 100.0,
            'max_discount_amount' => 50.0,
        ]);

        $this->validateCode('FLAT100', 5000.0)
            ->assertJsonPath('discount_amount', 100)
            ->assertJsonPath('max_discount_amount', null);
    }

    // ── Invalid codes ────────────────────────────────────────────────────────

    public function test_an_invalid_code_returns_the_new_fields_with_safe_defaults(): void
    {
        $this->validateCode('NOPE', 50000.0)
            ->assertJsonPath('valid', false)
            ->assertJsonPath('max_discount_amount', null)
            ->assertJsonPath('is_reward_capped', false)
            ->assertJsonPath('reward_amount', 0);
    }

    public function test_an_expired_capped_promo_is_still_rejected(): void
    {
        $this->makeRewardCoupon(['max_discount_amount' => 1000.0, 'expires_at' => now()->subDay()]);

        $this->validateCode('HAPPYHOLIDAYS', 50000.0)
            ->assertJsonPath('valid', false)
            ->assertJsonPath('reward_amount', 0);
    }
}
