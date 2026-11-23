<?php

namespace Tests\Feature\Coupon\MaxDiscount;

use App\Jobs\SendAdminGiftCardRewardNotificationJob;
use App\Models\CouponRedemption;
use App\Models\DrTier;
use App\Models\GiftCardRewardClaim;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * Real checkouts with a coupon limit. The client request was:
 * "I want people to order however much they want, but we want to put a
 * limit of $1,000 for the gift card".
 *
 *   POST /api/cart/checkout           (card payment)
 *   POST /api/cart/checkout/deferred  (Request Invoice, pay later)
 *
 * The DR tier costs $6,000 per link so a two link order ($12,000) goes over
 * the $1,000 gift card limit without triggering the bulk discount.
 */
class CheckoutMaxDiscountTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private const LINK_PRICE = 6000.0;

    private User $client;

    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client', ['credit_balance' => 0]);

        $this->dr_tier = DrTier::create([
            'id'             => 'dr80',
            'label'          => 'DR 80+',
            'min_dr'         => 80,
            'max_dr'         => 89,
            'traffic_range'  => '100k+',
            'word_count'     => 1000,
            'price_per_link' => self::LINK_PRICE,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('verifyPaymentIntent')->andReturn(['verified' => true]);
        $stripe->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $stripe->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);
        $this->app->instance(StripeService::class, $stripe);
    }

    private function linkBuildingItems(int $quantity): array
    {
        return [[
            'dr_tier_id' => $this->dr_tier->id,
            'quantity'   => $quantity,
            'unit_price' => self::LINK_PRICE,
            'placements' => [[
                'row_index'    => 0,
                'keyword'      => 'holiday keyword',
                'landing_page' => 'https://example.com/page',
                'exact_match'  => false,
            ]],
        ]];
    }

    private function payByCard(array $coupon_ids, int $quantity, float $total_amount, ?array $gift_card_reward = null): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout', array_filter([
            'payment_method_id'   => 'pi_test_card',
            'total_amount'        => $total_amount,
            'session_id'          => (string) Str::uuid(),
            'order_title'         => 'Big Holiday Order',
            'billing'             => [
                'company'     => 'Test Co',
                'address'     => '123 Main St',
                'city'        => 'Boise',
                'state'       => 'ID',
                'country'     => 'US',
                'postal_code' => '83701',
            ],
            'coupon_ids'          => $coupon_ids,
            'gift_card_reward'    => $gift_card_reward,
            'link_building_items' => $this->linkBuildingItems($quantity),
        ], fn ($value) => $value !== null));
    }

    private function payLater(array $coupon_ids, int $quantity, float $total_amount): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout/deferred', [
            'deferred_payment'    => true,
            'total_amount'        => $total_amount,
            'session_id'          => (string) Str::uuid(),
            'order_title'         => 'Big Holiday Invoice Order',
            'coupon_ids'          => $coupon_ids,
            'link_building_items' => $this->linkBuildingItems($quantity),
        ]);
    }

    // ── HAPPYHOLIDAYS gift card ──────────────────────────────────────────────

    public function test_card_checkout_over_ten_thousand_dollars_earns_a_one_thousand_dollar_gift_card(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $response = $this->payByCard([$coupon->id], 2, 12000.0, [
            'recipient_email' => 'gifts@example.com',
            'gift_card_brand' => 'amazon',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.gift_card_reward.reward_amount', 1000)
            ->assertJsonPath('data.gift_card_reward.max_reward_amount', 1000)
            ->assertJsonPath('data.gift_card_reward.is_reward_capped', true)
            ->assertJsonPath('data.gift_card_reward.status', GiftCardRewardClaim::STATUS_SUBMITTED);

        // The client still pays the full price, the promo never discounts.
        $this->assertEquals(12000.0, (float) $response->json('data.orders.0.total_amount'));

        $claim = GiftCardRewardClaim::where('coupon_id', $coupon->id)->firstOrFail();
        $this->assertSame(12000.0, $claim->order_total);
        $this->assertSame(10.0, $claim->reward_percentage);
        $this->assertSame(1000.0, $claim->reward_amount);
        $this->assertSame(1000.0, $claim->max_reward_amount);
        $this->assertSame(1, $coupon->fresh()->times_used);

        Queue::assertPushed(SendAdminGiftCardRewardNotificationJob::class, fn ($job) => $job->claim_id === $claim->id);
    }

    public function test_card_checkout_below_the_limit_earns_the_full_ten_percent(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->payByCard([$coupon->id], 1, 6000.0)
            ->assertOk()
            ->assertJsonPath('data.gift_card_reward.reward_amount', 600)
            ->assertJsonPath('data.gift_card_reward.is_reward_capped', false);
    }

    public function test_card_checkout_without_a_limit_keeps_the_old_behavior(): void
    {
        $coupon = $this->makeRewardCoupon();

        $this->payByCard([$coupon->id], 2, 12000.0)
            ->assertOk()
            ->assertJsonPath('data.gift_card_reward.reward_amount', 1200)
            ->assertJsonPath('data.gift_card_reward.max_reward_amount', null)
            ->assertJsonPath('data.gift_card_reward.is_reward_capped', false);
    }

    public function test_pay_later_checkout_is_capped_too(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->payLater([$coupon->id], 3, 18000.0)
            ->assertOk()
            ->assertJsonPath('data.gift_card_reward.reward_amount', 1000)
            ->assertJsonPath('data.gift_card_reward.is_reward_capped', true);

        $claim = GiftCardRewardClaim::where('coupon_id', $coupon->id)->firstOrFail();
        $this->assertSame(18000.0, $claim->order_total);
        $this->assertSame(1000.0, $claim->reward_amount);
    }

    public function test_each_order_has_its_own_limit(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->payByCard([$coupon->id], 2, 12000.0)->assertOk();
        $this->payByCard([$coupon->id], 3, 18000.0)->assertOk();

        $rewards = GiftCardRewardClaim::where('coupon_id', $coupon->id)->pluck('reward_amount')->all();

        // The limit is per order: two big orders earn two $1,000 gift cards.
        $this->assertEquals([1000.0, 1000.0], $rewards);
        $this->assertSame(2, $coupon->fresh()->times_used);
    }

    public function test_raising_the_limit_later_does_not_change_an_earned_gift_card(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);
        $this->payByCard([$coupon->id], 2, 12000.0)->assertOk();

        $coupon->update(['max_discount_amount' => 5000.0]);

        $claim = GiftCardRewardClaim::where('coupon_id', $coupon->id)->firstOrFail();
        $this->assertSame(1000.0, $claim->reward_amount);
        $this->assertSame(1000.0, $claim->max_reward_amount);
    }

    // ── Percentage coupons ───────────────────────────────────────────────────

    public function test_card_checkout_with_a_capped_percentage_coupon_discounts_only_the_limit(): void
    {
        $coupon = $this->makeRegularCoupon(['code' => 'SAVE20', 'discount_value' => 20.0, 'max_discount_amount' => 300.0]);

        $response = $this->payByCard([$coupon->id], 2, 11700.0);

        $response->assertOk();
        $this->assertEquals(11700.0, (float) $response->json('data.orders.0.total_amount'));
        $this->assertDatabaseHas('link_building_order_coupons', [
            'coupon_id'       => $coupon->id,
            'discount_amount' => 300.0,
        ]);

        $redemption = CouponRedemption::where('coupon_id', $coupon->id)->firstOrFail();
        $this->assertEquals(300.0, $redemption->discount_amount);
        $this->assertEquals(11700.0, $redemption->total_amount);
    }

    public function test_pay_later_with_a_capped_percentage_coupon_discounts_only_the_limit(): void
    {
        $coupon = $this->makeRegularCoupon(['code' => 'SAVE20', 'discount_value' => 20.0, 'max_discount_amount' => 300.0]);

        $response = $this->payLater([$coupon->id], 2, 11700.0);

        $response->assertOk();
        $this->assertEquals(11700.0, (float) $response->json('data.orders.0.total_amount'));
        $this->assertDatabaseHas('link_building_order_coupons', [
            'coupon_id'       => $coupon->id,
            'discount_amount' => 300.0,
        ]);
    }
}
