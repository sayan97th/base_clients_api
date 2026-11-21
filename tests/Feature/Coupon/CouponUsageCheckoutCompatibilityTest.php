<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\DrTier;
use App\Models\NewContentTier;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Every coupon type a client can use on the site must show up correctly in
 * the admin coupon usage view after a real checkout, without changing the
 * checkout result itself.
 */
class CouponUsageCheckoutCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();

        foreach (['client' => 'Client', 'admin' => 'Admin'] as $name => $display_name) {
            Role::firstOrCreate(['name' => $name], ['display_name' => $display_name, 'description' => $display_name]);
        }

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 0]);
        $this->client->assignRole('client');

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

        NewContentTier::create([
            'id'              => 'nc-basic',
            'label'           => 'Basic Article',
            'turnaround_time' => '6 Business Days',
            'price'           => 100.0,
            'is_active'       => true,
        ]);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('verifyPaymentIntent')->andReturn(['verified' => true]);
        $stripe->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $stripe->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);
        $this->app->instance(StripeService::class, $stripe);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'           => 'CONF25',
            'name'           => 'Conference 25',
            'discount_type'  => Coupon::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => 25.0,
            'applies_to'     => 'all',
            'is_active'      => true,
            'times_used'     => 0,
            'product_types'  => null,
        ], $overrides));
    }

    private function linkBuildingItems(): array
    {
        return [$this->linkBuildingItem('dr50', 400.0)];
    }

    private function linkBuildingItem(string $dr_tier_id, float $unit_price): array
    {
        return [
            'dr_tier_id' => $dr_tier_id,
            'quantity'   => 1,
            'unit_price' => $unit_price,
            'placements' => [[
                'row_index'    => 0,
                'keyword'      => 'promo keyword',
                'landing_page' => 'https://example.com/page',
                'exact_match'  => false,
            ]],
        ];
    }

    private function newContentItems(): array
    {
        return [[
            'tier_id'     => 'nc-basic',
            'quantity'    => 1,
            'unit_price'  => 100.0,
            'intake_rows' => [[
                'keyword_phrase'     => 'seo tips',
                'secondary_keywords' => null,
                'type_of_content'    => 'Blog Article',
                'notes'              => null,
            ]],
        ]];
    }

    private function cardCheckout(
        array $coupon_ids,
        float $total_amount,
        bool $with_new_content = false,
        ?array $link_building_items = null,
    ): TestResponse {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout', [
            'payment_method_id'          => 'pi_test_card',
            'total_amount'               => $total_amount,
            'session_id'                 => (string) Str::uuid(),
            'order_title'                => 'Promo Order',
            'billing'                    => [
                'company'     => 'Kennedy Co',
                'address'     => '123 Main St',
                'city'        => 'Boise',
                'state'       => 'ID',
                'country'     => 'US',
                'postal_code' => '83701',
            ],
            'coupon_ids'                 => $coupon_ids,
            'link_building_items'        => $link_building_items ?? $this->linkBuildingItems(),
            'new_content_items'          => $with_new_content ? $this->newContentItems() : null,
            'content_optimization_items' => null,
            'content_brief_items'        => null,
            'credits_amount'             => 0,
        ]);
    }

    private function usageOf(Coupon $coupon): TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/coupons/{$coupon->id}/usages")
            ->assertOk();
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    public function test_percentage_coupon_from_card_checkout(): void
    {
        $coupon = $this->makeCoupon();

        $this->cardCheckout([$coupon->id], 300.0)->assertOk();

        $usage = $this->usageOf($coupon)->assertJsonPath('summary.order_count', 1)->json('data.0');

        $this->assertEqualsWithDelta(100.0, $usage['discount_amount'], 0.001);
        $this->assertEqualsWithDelta(300.0, $usage['total_amount'], 0.001);
        $this->assertSame('percentage', $usage['coupon_terms']['discount_type']);
        $this->assertSame('Kennedy Co', $usage['client']['company']);
        $this->assertNotNull($usage['order_url']);
    }

    public function test_fixed_amount_coupon_from_card_checkout(): void
    {
        $coupon = $this->makeCoupon([
            'code'           => 'SAVE50',
            'discount_type'  => Coupon::DISCOUNT_TYPE_FIXED_AMOUNT,
            'discount_value' => 50.0,
        ]);

        $this->cardCheckout([$coupon->id], 350.0)->assertOk();

        $usage = $this->usageOf($coupon)->json('data.0');

        $this->assertEqualsWithDelta(50.0, $usage['discount_amount'], 0.001);
        $this->assertEqualsWithDelta(350.0, $usage['total_amount'], 0.001);
        $this->assertSame('fixed_amount', $usage['coupon_terms']['discount_type']);
    }

    public function test_product_restricted_coupon_only_lists_the_qualifying_order(): void
    {
        $coupon = $this->makeCoupon(['product_types' => ['new_content']]);

        // Link building $400 full price + new content $100 with 25% off.
        $this->cardCheckout([$coupon->id], 475.0, true)->assertOk();

        $response = $this->usageOf($coupon)->assertJsonPath('summary.order_count', 1);

        $this->assertSame('new_content', $response->json('data.0.product_type'));
        $this->assertEqualsWithDelta(25.0, $response->json('data.0.discount_amount'), 0.001);
        $this->assertSame('Basic Article', $response->json('data.0.items.0.label'));
    }

    public function test_coupon_used_in_a_multi_product_checkout_counts_one_checkout(): void
    {
        $coupon = $this->makeCoupon();

        $this->cardCheckout([$coupon->id], 375.0, true)->assertOk();

        $this->usageOf($coupon)
            ->assertJsonPath('summary.order_count', 2)
            ->assertJsonPath('summary.checkout_count', 1);

        $this->assertSame(1, $coupon->fresh()->times_used);
    }

    public function test_coupon_from_deferred_checkout_shows_the_pending_payment_order(): void
    {
        $coupon = $this->makeCoupon();

        $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout/deferred', [
            'deferred_payment'           => true,
            'total_amount'               => 300.0,
            'session_id'                 => (string) Str::uuid(),
            'order_title'                => 'Pay later order',
            'order_notes'                => null,
            'coupon_ids'                 => [$coupon->id],
            'link_building_items'        => $this->linkBuildingItems(),
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
        ])->assertOk();

        $usage = $this->usageOf($coupon)->json('data.0');

        $this->assertSame('payment_pending', $usage['status']);
        $this->assertEqualsWithDelta(100.0, $usage['discount_amount'], 0.001);
        $this->assertNotNull($usage['invoice']);
    }

    public function test_product_restricted_gift_card_reward_only_lists_the_qualifying_order(): void
    {
        $coupon = $this->makeCoupon([
            'code'           => 'HAPPYHOLIDAYS',
            'discount_type'  => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value' => 10.0,
            'product_types'  => ['link_building'],
        ]);

        // A reward never lowers the price: $400 + $100.
        $this->cardCheckout([$coupon->id], 500.0, true)->assertOk();

        $response = $this->usageOf($coupon)->assertJsonPath('summary.order_count', 1);

        $this->assertSame('link_building', $response->json('data.0.product_type'));
        $this->assertArrayNotHasKey('reward_amount', $response->json('data.0'));
        $this->assertEqualsWithDelta(40.0, $response->json('data.0.gift_card_claim.reward_amount'), 0.001);
        $this->assertEqualsWithDelta(0.0, $response->json('data.0.discount_amount'), 0.001);
    }

    public function test_a_multi_product_checkout_earns_one_gift_card_for_the_whole_order(): void
    {
        $coupon = $this->makeCoupon([
            'code'           => 'HAPPYHOLIDAYS',
            'discount_type'  => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value' => 10.0,
        ]);

        $this->cardCheckout([$coupon->id], 500.0, true)->assertOk();

        $response = $this->usageOf($coupon)
            ->assertJsonCount(1, 'checkouts')
            ->assertJsonPath('summary.gift_card_count', 1);

        // One gift card for the order, never a share per product order or item.
        $this->assertEqualsWithDelta(50.0, $response->json('checkouts.0.reward_amount'), 0.001);
        $this->assertEqualsWithDelta(50.0, $response->json('summary.total_reward'), 0.001);
        $this->assertEqualsWithDelta(0.0, CouponRedemption::sum('reward_amount'), 0.001);

        $usages = collect($response->json('data'))->keyBy('product_type');

        $this->assertCount(2, $usages);
        $this->assertSame($usages['link_building']['gift_card_claim']['id'], $usages['new_content']['gift_card_claim']['id']);

        foreach ($usages as $usage) {
            $this->assertArrayNotHasKey('reward_amount', $usage);
            $this->assertEqualsWithDelta(50.0, $usage['gift_card_claim']['reward_amount'], 0.001);

            foreach ($usage['items'] as $item) {
                $this->assertArrayNotHasKey('reward_amount', $item);
            }
        }
    }

    public function test_gift_card_reward_coupons_are_rejected_on_single_product_endpoints(): void
    {
        $coupon = $this->makeCoupon([
            'code'           => 'HAPPYHOLIDAYS',
            'discount_type'  => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value' => 10.0,
        ]);

        $this->actingAs($this->client, 'api')->postJson('/api/link-building/orders', [
            'order_title'  => 'Direct order',
            'total_amount' => 400.0,
            'coupon_ids'   => [$coupon->id],
            'items'        => $this->linkBuildingItems(),
            'payment'      => ['payment_method_id' => 'pi_test_card'],
            'billing'      => [
                'company'     => null,
                'address'     => '123 Main St',
                'city'        => 'Boise',
                'state'       => 'ID',
                'country'     => 'US',
                'postal_code' => '83701',
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This promotion can only be applied from the cart checkout.');

        $this->assertSame(0, $coupon->fresh()->times_used);
        $this->assertSame(0, CouponRedemption::count());
    }

    // ─── Checkout view ────────────────────────────────────────────────────────

    public function test_a_multi_product_checkout_is_listed_as_one_checkout_with_every_order(): void
    {
        $coupon = $this->makeCoupon();

        // $400 link + $100 article, 25% off both.
        $this->cardCheckout([$coupon->id], 375.0, true)->assertOk();

        $response = $this->usageOf($coupon)
            ->assertJsonCount(1, 'checkouts')
            ->assertJsonPath('checkouts.0.order_count', 2)
            ->assertJsonPath('checkouts.0.applied_order_count', 2)
            ->assertJsonPath('checkouts.0.items_quantity', 2)
            ->assertJsonPath('summary.multi_product_checkout_count', 1);

        $checkout = $response->json('checkouts.0');

        $this->assertEqualsWithDelta(500.0, $checkout['subtotal_before_discount'], 0.001);
        $this->assertEqualsWithDelta(125.0, $checkout['discount_amount'], 0.001);
        $this->assertEqualsWithDelta(375.0, $checkout['total_amount'], 0.001);
        $this->assertEqualsWithDelta(375.0, $response->json('summary.average_checkout_value'), 0.001);
        $this->assertEqualsCanonicalizing(['link_building', 'new_content'], $checkout['product_types']);
        $this->assertNotNull($checkout['session_url']);
        $this->assertSame('Promo Order', $checkout['title']);
    }

    public function test_the_checkout_keeps_the_products_the_coupon_did_not_discount(): void
    {
        $coupon = $this->makeCoupon(['product_types' => ['new_content']]);

        // Link building $400 full price + new content $100 with 25% off.
        $this->cardCheckout([$coupon->id], 475.0, true)->assertOk();

        $response = $this->usageOf($coupon)
            ->assertJsonPath('summary.order_count', 1)
            ->assertJsonPath('summary.checkout_count', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('checkouts.0.order_count', 2)
            ->assertJsonPath('checkouts.0.applied_order_count', 1)
            ->assertJsonPath('checkouts.0.applied_product_types', ['new_content']);

        $orders = collect($response->json('checkouts.0.orders'))->keyBy('product_type');

        $this->assertTrue($orders['new_content']['is_coupon_applied']);
        $this->assertTrue($orders['new_content']['items'][0]['is_coupon_applied']);
        $this->assertEqualsWithDelta(25.0, $orders['new_content']['items'][0]['discount_amount'], 0.001);

        $this->assertFalse($orders['link_building']['is_coupon_applied']);
        $this->assertFalse($orders['link_building']['items'][0]['is_coupon_applied']);
        $this->assertEqualsWithDelta(0.0, $orders['link_building']['discount_amount'], 0.001);
        $this->assertSame('DR 50+', $orders['link_building']['items'][0]['label']);

        // Discounted orders are listed first.
        $this->assertSame('new_content', $response->json('checkouts.0.orders.0.product_type'));
        $this->assertEqualsWithDelta(475.0, $response->json('checkouts.0.total_amount'), 0.001);
        $this->assertEqualsWithDelta(100.0, $response->json('checkouts.0.eligible_subtotal'), 0.001);
        $this->assertSame(1, $coupon->fresh()->times_used);
    }

    public function test_a_dr_tier_coupon_marks_only_the_items_it_discounted(): void
    {
        DrTier::create([
            'id'             => 'dr70',
            'label'          => 'DR 70+',
            'min_dr'         => 70,
            'max_dr'         => 79,
            'traffic_range'  => '50k+',
            'word_count'     => 700,
            'price_per_link' => 600.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);

        $coupon = $this->makeCoupon(['applies_to' => Coupon::APPLIES_TO_DR_TIERS]);
        $coupon->drTiers()->attach('dr70');

        // $400 + $600, 25% off the DR 70+ link only.
        $this->cardCheckout([$coupon->id], 850.0, false, [
            $this->linkBuildingItem('dr50', 400.0),
            $this->linkBuildingItem('dr70', 600.0),
        ])->assertOk();

        $items = collect($this->usageOf($coupon)->json('checkouts.0.orders.0.items'))->keyBy('tier_id');

        $this->assertFalse($items['dr50']['is_coupon_applied']);
        $this->assertEqualsWithDelta(0.0, $items['dr50']['discount_amount'], 0.001);
        $this->assertTrue($items['dr70']['is_coupon_applied']);
        $this->assertEqualsWithDelta(150.0, $items['dr70']['discount_amount'], 0.001);
    }

    public function test_the_checkout_keeps_names_and_prices_after_products_change(): void
    {
        $coupon = $this->makeCoupon(['product_types' => ['new_content']]);

        $this->cardCheckout([$coupon->id], 475.0, true)->assertOk();

        DrTier::where('id', 'dr50')->update(['label' => 'DR 50 Renamed', 'price_per_link' => 999.0]);
        NewContentTier::where('id', 'nc-basic')->update(['label' => 'Renamed Article', 'price' => 555.0]);

        $orders = collect($this->usageOf($coupon)->json('checkouts.0.orders'))->keyBy('product_type');

        $this->assertSame('DR 50+', $orders['link_building']['items'][0]['label']);
        $this->assertEqualsWithDelta(400.0, $orders['link_building']['items'][0]['unit_price'], 0.001);
        $this->assertSame('Basic Article', $orders['new_content']['items'][0]['label']);
        $this->assertEqualsWithDelta(100.0, $orders['new_content']['items'][0]['unit_price'], 0.001);
    }

    public function test_a_product_restricted_gift_card_reward_keeps_the_rest_of_the_checkout(): void
    {
        $coupon = $this->makeCoupon([
            'code'           => 'HAPPYHOLIDAYS',
            'discount_type'  => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value' => 10.0,
            'product_types'  => ['link_building'],
        ]);

        $this->cardCheckout([$coupon->id], 500.0, true)->assertOk();

        $response = $this->usageOf($coupon)
            ->assertJsonPath('checkouts.0.order_count', 2)
            ->assertJsonPath('checkouts.0.applied_product_types', ['link_building']);

        $orders = collect($response->json('checkouts.0.orders'))->keyBy('product_type');

        $this->assertTrue($orders['link_building']['items'][0]['is_coupon_applied']);
        $this->assertArrayNotHasKey('reward_amount', $orders['link_building']['items'][0]);
        $this->assertFalse($orders['new_content']['is_coupon_applied']);
        $this->assertEqualsWithDelta(40.0, $response->json('checkouts.0.reward_amount'), 0.001);
        $this->assertEqualsWithDelta(500.0, $response->json('checkouts.0.total_amount'), 0.001);
    }
}
