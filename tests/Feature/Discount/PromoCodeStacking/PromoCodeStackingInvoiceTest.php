<?php

namespace Tests\Feature\Discount\PromoCodeStacking;

use App\Models\ContentBriefTier;
use App\Models\DrTier;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
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
 * What the client and the admins see after checkout: the invoice and the
 * order detail only show a bulk discount when the order really received it.
 *
 * Link Building: 10 links at $400 ($4,000, bulk price $3,600).
 * Content Brief: 1 brief at $80 (never bulk discounted by the API).
 */
class PromoCodeStackingInvoiceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithBulkDiscounts;
    use InteractsWithGiftCardRewards;

    private User $client;

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

        ContentBriefTier::create([
            'id'              => 'cb-standard',
            'label'           => 'Standard Brief',
            'turnaround_days' => 3,
            'price'           => 80.0,
            'is_active'       => true,
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
            'dr_tier_id' => 'dr50',
            'quantity'   => 10,
            'unit_price' => 400.0,
            'placements' => [['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false]],
        ]];
    }

    private function checkout(array $overrides): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout', array_merge([
            'payment_method_id'   => 'pi_test_card',
            'session_id'          => (string) Str::uuid(),
            'billing'             => ['company' => null, 'address' => '', 'city' => '', 'state' => '', 'country' => '', 'postal_code' => ''],
            'coupon_ids'          => [],
            'link_building_items' => $this->linkBuildingItems(),
        ], $overrides));
    }

    private function multiProductCheckout(array $coupon_ids, float $total_amount): TestResponse
    {
        return $this->checkout([
            'total_amount'        => $total_amount,
            'coupon_ids'          => $coupon_ids,
            'content_brief_items' => [[
                'tier_id'    => 'cb-standard',
                'quantity'   => 1,
                'unit_price' => 80.0,
            ]],
        ]);
    }

    private function latestInvoice(): Invoice
    {
        return Invoice::where('user_id', $this->client->id)->latest('id')->firstOrFail();
    }

    private function orderDetail(): TestResponse
    {
        $order = LinkBuildingOrder::where('user_id', $this->client->id)->firstOrFail();

        return $this->actingAs($this->client, 'api')->getJson("/api/link-building/orders/{$order->id}");
    }

    // ─── Multi product session invoice ────────────────────────────────────────

    public function test_session_invoice_has_no_discount_when_a_money_back_promo_code_replaced_the_bulk_discount(): void
    {
        $this->makeBulkDiscount();

        $this->multiProductCheckout([$this->makeRewardCoupon()->id], 4080.0)->assertOk();

        $invoice = $this->latestInvoice();
        $this->assertEquals(0.0, (float) $invoice->discount_amount);
        $this->assertEquals(4080.0, (float) $invoice->total_amount);
    }

    public function test_session_invoice_keeps_the_bulk_discount_when_stacking_is_allowed(): void
    {
        $this->makeStackableBulkDiscount();

        $this->multiProductCheckout([$this->makeRewardCoupon()->id], 3680.0)->assertOk();

        $invoice = $this->latestInvoice();
        $this->assertEquals(400.0, (float) $invoice->discount_amount);
        $this->assertEquals(3680.0, (float) $invoice->total_amount);
    }

    public function test_session_gift_card_is_based_on_every_order_at_full_price(): void
    {
        $this->makeBulkDiscount();

        $this->multiProductCheckout([$this->makeRewardCoupon()->id], 4080.0)
            ->assertOk()
            ->assertJsonPath('data.gift_card_reward.reward_amount', 408);
    }

    // ─── Credits ──────────────────────────────────────────────────────────────

    public function test_credits_invoice_shows_no_bulk_discount_because_credits_skip_every_promotion(): void
    {
        $this->makeBulkDiscount();
        $this->client->update(['credit_balance' => 4000.0]);

        $this->checkout([
            'payment_method_id' => 'credits_pay_4000',
            'total_amount'      => 4000.0,
        ])->assertOk();

        $this->assertDatabaseHas('link_building_orders', [
            'user_id'      => $this->client->id,
            'total_amount' => 4000.0,
        ]);
        $this->assertEquals(0.0, (float) $this->latestInvoice()->discount_amount);
    }

    // ─── Order detail ─────────────────────────────────────────────────────────

    public function test_order_detail_lists_no_discount_when_a_money_back_promo_code_replaced_it(): void
    {
        $this->makeBulkDiscount();
        $this->checkout(['total_amount' => 4000.0, 'coupon_ids' => [$this->makeRewardCoupon()->id]])->assertOk();

        $this->orderDetail()
            ->assertOk()
            ->assertJsonPath('data.discounts', [])
            ->assertJsonPath('data.coupons', []);
    }

    public function test_order_detail_lists_the_bulk_discount_when_stacking_is_allowed(): void
    {
        $this->makeStackableBulkDiscount();
        $this->checkout(['total_amount' => 3600.0, 'coupon_ids' => [$this->makeRewardCoupon()->id]])->assertOk();

        $this->orderDetail()
            ->assertOk()
            ->assertJsonPath('data.discounts.0.name', 'Bulk Link Building Discount')
            ->assertJsonPath('data.discounts.0.discount_amount', 400);
    }
}
