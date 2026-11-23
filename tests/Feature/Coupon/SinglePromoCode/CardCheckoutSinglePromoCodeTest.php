<?php

namespace Tests\Feature\Coupon\SinglePromoCode;

use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\GiftCardRewardClaim;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\Coupon\InteractsWithSinglePromoCode;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * One promo code per order, "Pay now" card checkout.
 * POST /api/cart/checkout
 *
 * Stacked promo codes are rejected before the card payment is verified or
 * captured, so the client is never charged for an order that fails.
 */
class CardCheckoutSinglePromoCodeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;
    use InteractsWithSinglePromoCode;

    private User $client;
    private DrTier $dr_tier;
    private MockInterface $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        $this->seedGiftCardRewardRoles();
        $this->client  = $this->makeUserWithRole('client', ['credit_balance' => 0]);
        $this->dr_tier = $this->makeDrTier();

        $this->stripe = Mockery::mock(StripeService::class);
        $this->app->instance(StripeService::class, $this->stripe);
    }

    private function allowCardPayment(): void
    {
        $this->stripe->shouldReceive('verifyPaymentIntent')->andReturn(['verified' => true]);
        $this->stripe->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $this->stripe->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);
    }

    private function expectNoCardActivity(): void
    {
        $this->stripe->shouldNotReceive('verifyPaymentIntent');
        $this->stripe->shouldNotReceive('capturePaymentIntent');
    }

    private function payByCard(array $coupon_ids, float $total_amount): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout', [
            'payment_method_id'   => 'pi_test_card',
            'total_amount'        => $total_amount,
            'session_id'          => (string) Str::uuid(),
            'order_title'         => 'Single Promo Card Order',
            'billing'             => $this->billingDetails(),
            'coupon_ids'          => $coupon_ids,
            'link_building_items' => $this->linkBuildingItems($this->dr_tier->id),
            'credits_amount'      => 0,
        ]);
    }

    public function test_two_regular_promo_codes_are_rejected_before_charging_the_card(): void
    {
        $this->expectNoCardActivity();
        $first  = $this->makePromoCode('SAVE10');
        $second = $this->makePromoCode('SAVE20', 20.0);

        $this->payByCard([$first->id, $second->id], 560.0)
            ->assertStatus(422)
            ->assertJsonPath('message', Coupon::COMBINATION_ERROR_MESSAGE)
            ->assertJsonValidationErrors(['coupon_ids' => Coupon::COMBINATION_ERROR_MESSAGE]);

        $this->assertSame(0, LinkBuildingOrder::count());
        $this->assertSame(0, $first->fresh()->times_used);
        $this->assertSame(0, $second->fresh()->times_used);
    }

    public function test_the_money_back_promo_and_a_regular_code_are_rejected_before_charging_the_card(): void
    {
        $this->expectNoCardActivity();
        $holiday = $this->makeRewardCoupon();
        $regular = $this->makePromoCode('SAVE10');

        $this->payByCard([$holiday->id, $regular->id], 720.0)
            ->assertStatus(422)
            ->assertJsonPath('message', Coupon::COMBINATION_ERROR_MESSAGE);

        $this->assertSame(0, LinkBuildingOrder::count());
        $this->assertSame(0, GiftCardRewardClaim::count());
        $this->assertSame(0, $holiday->fresh()->times_used);
    }

    public function test_a_single_promo_code_is_still_accepted(): void
    {
        $this->allowCardPayment();
        $coupon = $this->makePromoCode('SAVE10');

        $this->payByCard([$coupon->id], 720.0)->assertOk();

        $this->assertSame(1, LinkBuildingOrder::count());
        $this->assertSame(1, $coupon->fresh()->times_used);
    }

    public function test_the_money_back_promo_alone_is_still_accepted(): void
    {
        $this->allowCardPayment();
        $holiday = $this->makeRewardCoupon();

        $this->payByCard([$holiday->id], 800.0)->assertOk();

        $this->assertSame(1, $holiday->fresh()->times_used);
        $this->assertSame(1, GiftCardRewardClaim::count());
    }
}
