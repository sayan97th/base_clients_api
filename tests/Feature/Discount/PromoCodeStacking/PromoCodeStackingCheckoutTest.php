<?php

namespace Tests\Feature\Discount\PromoCodeStacking;

use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\GiftCardRewardClaim;
use App\Models\Invoice;
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
 * Promotions do not stack by default: when a client applies any promo code
 * (price discount or money back), the automatic bulk discount is removed from
 * the order. When the admin enables "allow_promo_code_stacking" on the
 * discount, the client gets both the bulk discount and the promo code.
 *
 * Every scenario orders 10 links at $400 ($4,000), which meets the bulk
 * discount threshold (10% off, $3,600).
 */
class PromoCodeStackingCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithBulkDiscounts;
    use InteractsWithGiftCardRewards;

    private const LINK_QUANTITY = 10;
    private const FULL_PRICE    = 4000.0;
    private const BULK_PRICE    = 3600.0;

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

        $stripe_mock = Mockery::mock(StripeService::class);
        $stripe_mock->shouldReceive('verifyPaymentIntent')->andReturn(['verified' => true]);
        $stripe_mock->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $stripe_mock->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);
        $this->app->instance(StripeService::class, $stripe_mock);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function linkBuildingItems(): array
    {
        return [[
            'dr_tier_id' => $this->dr_tier->id,
            'quantity'   => self::LINK_QUANTITY,
            'unit_price' => 400.0,
            'placements' => [['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false]],
        ]];
    }

    private function cardCheckout(array $coupon_ids, float $total_amount): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout', [
            'payment_method_id'   => 'pi_test_card',
            'total_amount'        => $total_amount,
            'session_id'          => (string) Str::uuid(),
            'billing'             => ['company' => null, 'address' => '', 'city' => '', 'state' => '', 'country' => '', 'postal_code' => ''],
            'coupon_ids'          => $coupon_ids,
            'link_building_items' => $this->linkBuildingItems(),
        ]);
    }

    private function deferredCheckout(array $coupon_ids, float $total_amount): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout/deferred', [
            'deferred_payment'           => true,
            'total_amount'               => $total_amount,
            'session_id'                 => (string) Str::uuid(),
            'order_title'                => 'Pay Later Order',
            'order_notes'                => null,
            'coupon_ids'                 => $coupon_ids,
            'link_building_items'        => $this->linkBuildingItems(),
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
        ]);
    }

    private function orderTotal(TestResponse $response): float
    {
        return (float) $response->json('data.orders.0.total_amount');
    }

    private function latestInvoice(): Invoice
    {
        return Invoice::where('user_id', $this->client->id)->latest('id')->firstOrFail();
    }

    // ─── Without a promo code, the bulk discount is applied ──────────────────

    public function test_bulk_discount_applies_when_no_promo_code_is_used(): void
    {
        $this->makeBulkDiscount();

        $response = $this->cardCheckout([], self::BULK_PRICE);

        $response->assertOk();
        $this->assertEquals(self::BULK_PRICE, $this->orderTotal($response));
        $this->assertEquals(400.0, (float) $this->latestInvoice()->discount_amount);
    }

    // ─── Money back promo codes (gift card rewards) ──────────────────────────

    public function test_money_back_promo_code_replaces_the_bulk_discount_by_default(): void
    {
        $this->makeBulkDiscount();
        $coupon = $this->makeRewardCoupon();

        $response = $this->cardCheckout([$coupon->id], self::FULL_PRICE);

        $response->assertOk()->assertJsonPath('data.gift_card_reward.reward_amount', 400);
        $this->assertEquals(self::FULL_PRICE, $this->orderTotal($response));
        $this->assertDatabaseHas('link_building_orders', [
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => self::FULL_PRICE,
            'total_amount'             => self::FULL_PRICE,
        ]);
    }

    public function test_invoice_shows_no_bulk_discount_when_a_money_back_promo_code_replaced_it(): void
    {
        $this->makeBulkDiscount();
        $coupon = $this->makeRewardCoupon();

        $this->cardCheckout([$coupon->id], self::FULL_PRICE)->assertOk();

        $invoice = $this->latestInvoice();
        $this->assertEquals(0.0, (float) $invoice->discount_amount);
        $this->assertNull($invoice->discount_type);
    }

    public function test_money_back_promo_code_replaces_the_bulk_discount_when_no_discount_record_exists(): void
    {
        $coupon = $this->makeRewardCoupon();

        $response = $this->cardCheckout([$coupon->id], self::FULL_PRICE);

        $response->assertOk();
        $this->assertEquals(self::FULL_PRICE, $this->orderTotal($response));
    }

    public function test_money_back_promo_code_keeps_the_bulk_discount_when_stacking_is_allowed(): void
    {
        $this->makeStackableBulkDiscount();
        $coupon = $this->makeRewardCoupon();

        $response = $this->cardCheckout([$coupon->id], self::BULK_PRICE);

        $response->assertOk()->assertJsonPath('data.gift_card_reward.reward_amount', 360);
        $this->assertEquals(self::BULK_PRICE, $this->orderTotal($response));
        $this->assertEquals(400.0, (float) $this->latestInvoice()->discount_amount);
    }

    public function test_stacking_is_blocked_when_any_active_discount_for_the_product_disallows_it(): void
    {
        $this->makeStackableBulkDiscount();
        $this->makeBulkDiscount(['name' => 'Store wide bulk', 'applies_to' => 'all']);
        $coupon = $this->makeRewardCoupon();

        $response = $this->cardCheckout([$coupon->id], self::FULL_PRICE);

        $response->assertOk();
        $this->assertEquals(self::FULL_PRICE, $this->orderTotal($response));
    }

    public function test_inactive_discounts_do_not_block_stacking(): void
    {
        $this->makeStackableBulkDiscount();
        $this->makeBulkDiscount(['name' => 'Old bulk', 'is_active' => false]);
        $coupon = $this->makeRewardCoupon();

        $response = $this->cardCheckout([$coupon->id], self::BULK_PRICE);

        $response->assertOk();
        $this->assertEquals(self::BULK_PRICE, $this->orderTotal($response));
    }

    public function test_deferred_checkout_also_replaces_the_bulk_discount_with_a_money_back_promo_code(): void
    {
        $this->makeBulkDiscount();
        $coupon = $this->makeRewardCoupon();

        $response = $this->deferredCheckout([$coupon->id], self::FULL_PRICE);

        $response->assertOk();
        $this->assertEquals(self::FULL_PRICE, $this->orderTotal($response));
        $this->assertDatabaseHas('gift_card_reward_claims', [
            'coupon_id'     => $coupon->id,
            'reward_amount' => 400.0,
            'status'        => GiftCardRewardClaim::STATUS_PENDING_DETAILS,
        ]);
    }

    public function test_deferred_checkout_keeps_the_bulk_discount_when_stacking_is_allowed(): void
    {
        $this->makeStackableBulkDiscount();
        $coupon = $this->makeRewardCoupon();

        $response = $this->deferredCheckout([$coupon->id], self::BULK_PRICE);

        $response->assertOk();
        $this->assertEquals(self::BULK_PRICE, $this->orderTotal($response));
    }

    // ─── Price discount promo codes ───────────────────────────────────────────

    public function test_price_discount_promo_code_replaces_the_bulk_discount_by_default(): void
    {
        $this->makeBulkDiscount();
        $coupon = $this->makeRegularCoupon(['discount_value' => 5.0]);

        $response = $this->cardCheckout([$coupon->id], 3800.0);

        $response->assertOk();
        $this->assertEquals(3800.0, $this->orderTotal($response));
        $this->assertDatabaseHas('link_building_order_coupons', [
            'coupon_id'       => $coupon->id,
            'discount_amount' => 200.0,
        ]);
    }

    public function test_price_discount_promo_code_replaces_the_bulk_discount_even_when_it_saves_less(): void
    {
        $this->makeBulkDiscount();
        $coupon = $this->makeRegularCoupon(['discount_type' => 'fixed_amount', 'discount_value' => 50.0]);

        $response = $this->cardCheckout([$coupon->id], 3950.0);

        $response->assertOk();
        $this->assertEquals(3950.0, $this->orderTotal($response));
    }

    public function test_price_discount_promo_code_and_bulk_discount_both_apply_when_combining_is_allowed(): void
    {
        // Both are calculated on the full subtotal: 10% bulk ($400) plus 5% promo ($200).
        $this->makeStackableBulkDiscount();
        $coupon = $this->makeRegularCoupon(['discount_value' => 5.0]);

        $response = $this->cardCheckout([$coupon->id], 3400.0);

        $response->assertOk();
        $this->assertEquals(3400.0, $this->orderTotal($response));
        $this->assertDatabaseHas('link_building_orders', [
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => self::FULL_PRICE,
            'total_amount'             => 3400.0,
        ]);
        $this->assertDatabaseHas('link_building_order_coupons', [
            'coupon_id'       => $coupon->id,
            'discount_amount' => 200.0,
        ]);
    }

    public function test_fixed_amount_promo_code_combines_with_the_bulk_discount_when_allowed(): void
    {
        // $4,000 minus the $400 bulk discount minus the $500 promo code.
        $this->makeStackableBulkDiscount();
        $coupon = $this->makeRegularCoupon(['discount_type' => 'fixed_amount', 'discount_value' => 500.0]);

        $response = $this->cardCheckout([$coupon->id], 3100.0);

        $response->assertOk();
        $this->assertEquals(3100.0, $this->orderTotal($response));
    }

    public function test_deferred_checkout_combines_both_when_combining_is_allowed(): void
    {
        $this->makeStackableBulkDiscount();
        $coupon = $this->makeRegularCoupon(['discount_value' => 5.0]);

        $response = $this->deferredCheckout([$coupon->id], 3400.0);

        $response->assertOk();
        $this->assertEquals(3400.0, $this->orderTotal($response));
    }

    public function test_deferred_checkout_replaces_the_bulk_discount_with_a_price_discount_promo_code_by_default(): void
    {
        $this->makeBulkDiscount();
        $coupon = $this->makeRegularCoupon(['discount_value' => 5.0]);

        $response = $this->deferredCheckout([$coupon->id], 3800.0);

        $response->assertOk();
        $this->assertEquals(3800.0, $this->orderTotal($response));
    }

    public function test_an_invalid_promo_code_does_not_remove_the_bulk_discount(): void
    {
        $this->makeBulkDiscount();
        $expired_coupon = $this->makeRegularCoupon(['expires_at' => now()->subDays(5)]);

        $response = $this->cardCheckout([$expired_coupon->id], self::BULK_PRICE);

        $response->assertOk();
        $this->assertEquals(self::BULK_PRICE, $this->orderTotal($response));
        $this->assertDatabaseCount('link_building_order_coupons', 0);
    }
}
