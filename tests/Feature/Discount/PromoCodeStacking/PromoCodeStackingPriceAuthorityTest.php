<?php

namespace Tests\Feature\Discount\PromoCodeStacking;

use App\Models\DrTier;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\Discount\InteractsWithBulkDiscounts;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * The API is the price authority: the amount it expects Stripe to have
 * authorized must follow the "promotions never stack" rule. A client that
 * tampers with the cart and pays the stacked price (bulk discount plus a money
 * back promo code) is rejected before any order is created.
 */
class PromoCodeStackingPriceAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithBulkDiscounts;
    use InteractsWithGiftCardRewards;

    private const FULL_PRICE = 4000.0;
    private const BULK_PRICE = 3600.0;

    private User $client;

    /** Amounts the API asked Stripe to verify, in call order. */
    private array $verified_amounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();

        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client', ['credit_balance' => 0]);

        DrTier::create([
            'id'             => 'dr50',
            'label'          => 'DR 50+',
            'min_dr'         => 50,
            'max_dr'         => 59,
            'traffic_range'  => '10k-20k',
            'word_count'     => 500,
            'price_per_link' => 400.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);
    }

    /**
     * Fakes a PaymentIntent authorized for $authorized_amount: Stripe only
     * verifies it when the API expects exactly that amount.
     */
    private function mockStripeAuthorizedFor(float $authorized_amount): void
    {
        $stripe_mock = Mockery::mock(StripeService::class);
        $stripe_mock->shouldReceive('verifyPaymentIntent')->andReturnUsing(
            function (string $payment_intent_id, ?float $expected_amount = null) use ($authorized_amount) {
                $this->verified_amounts[] = $expected_amount;

                return abs((float) $expected_amount - $authorized_amount) < 0.01
                    ? ['verified' => true]
                    : ['verified' => false, 'message' => 'Amount mismatch.'];
            }
        );
        $stripe_mock->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $stripe_mock->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);
        $this->app->instance(StripeService::class, $stripe_mock);
    }

    private function checkout(array $coupon_ids, float $total_amount): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout', [
            'payment_method_id'   => 'pi_test_card',
            'total_amount'        => $total_amount,
            'session_id'          => (string) Str::uuid(),
            'billing'             => ['company' => null, 'address' => '', 'city' => '', 'state' => '', 'country' => '', 'postal_code' => ''],
            'coupon_ids'          => $coupon_ids,
            'link_building_items' => [[
                'dr_tier_id' => 'dr50',
                'quantity'   => 10,
                'unit_price' => 400.0,
                'placements' => [['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false]],
            ]],
        ]);
    }

    public function test_the_api_expects_the_full_price_when_a_money_back_promo_code_replaces_the_bulk_discount(): void
    {
        $this->makeBulkDiscount();
        $this->mockStripeAuthorizedFor(self::FULL_PRICE);

        $this->checkout([$this->makeRewardCoupon()->id], self::FULL_PRICE)->assertOk();

        $this->assertEquals([self::FULL_PRICE], $this->verified_amounts);
    }

    public function test_a_payment_for_the_stacked_price_is_rejected_and_no_order_is_created(): void
    {
        $this->makeBulkDiscount();
        $this->mockStripeAuthorizedFor(self::BULK_PRICE);

        $this->checkout([$this->makeRewardCoupon()->id], self::BULK_PRICE)->assertStatus(402);

        $this->assertDatabaseCount('link_building_orders', 0);
        $this->assertDatabaseCount('gift_card_reward_claims', 0);
    }

    public function test_the_api_expects_the_bulk_price_when_stacking_is_allowed(): void
    {
        $this->makeStackableBulkDiscount();
        $this->mockStripeAuthorizedFor(self::BULK_PRICE);

        $this->checkout([$this->makeRewardCoupon()->id], self::BULK_PRICE)->assertOk();

        $this->assertEquals([self::BULK_PRICE], $this->verified_amounts);
    }

    public function test_the_api_expects_the_bulk_price_without_a_promo_code(): void
    {
        $this->makeBulkDiscount();
        $this->mockStripeAuthorizedFor(self::BULK_PRICE);

        $this->checkout([], self::BULK_PRICE)->assertOk();

        $this->assertEquals([self::BULK_PRICE], $this->verified_amounts);
    }

    public function test_the_api_expects_the_promo_price_for_a_price_discount_promo_code_by_default(): void
    {
        $this->makeBulkDiscount();
        $this->mockStripeAuthorizedFor(3800.0);

        $this->checkout([$this->makeRegularCoupon(['discount_value' => 5.0])->id], 3800.0)->assertOk();

        $this->assertEquals([3800.0], $this->verified_amounts);
    }

    public function test_a_payment_for_the_combined_price_is_rejected_by_default(): void
    {
        $this->makeBulkDiscount();
        $this->mockStripeAuthorizedFor(3400.0);

        $this->checkout([$this->makeRegularCoupon(['discount_value' => 5.0])->id], 3400.0)->assertStatus(402);

        $this->assertDatabaseCount('link_building_orders', 0);
    }

    public function test_the_api_expects_the_combined_price_when_combining_is_allowed(): void
    {
        $this->makeStackableBulkDiscount();
        $this->mockStripeAuthorizedFor(3400.0);

        $this->checkout([$this->makeRegularCoupon(['discount_value' => 5.0])->id], 3400.0)->assertOk();

        $this->assertEquals([3400.0], $this->verified_amounts);
    }
}
