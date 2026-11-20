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
        return [[
            'dr_tier_id' => 'dr50',
            'quantity'   => 1,
            'unit_price' => 400.0,
            'placements' => [[
                'row_index'    => 0,
                'keyword'      => 'promo keyword',
                'landing_page' => 'https://example.com/page',
                'exact_match'  => false,
            ]],
        ]];
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

    private function cardCheckout(array $coupon_ids, float $total_amount, bool $with_new_content = false): TestResponse
    {
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
            'link_building_items'        => $this->linkBuildingItems(),
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
        $this->assertEqualsWithDelta(40.0, $response->json('data.0.reward_amount'), 0.001);
        $this->assertEqualsWithDelta(0.0, $response->json('data.0.discount_amount'), 0.001);
    }

    public function test_gift_card_reward_is_split_by_order_total_across_products(): void
    {
        $coupon = $this->makeCoupon([
            'code'           => 'HAPPYHOLIDAYS',
            'discount_type'  => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value' => 10.0,
        ]);

        $this->cardCheckout([$coupon->id], 500.0, true)->assertOk();

        $usages = collect($this->usageOf($coupon)->json('data'))->keyBy('product_type');

        $this->assertEqualsWithDelta(40.0, $usages['link_building']['reward_amount'], 0.001);
        $this->assertEqualsWithDelta(10.0, $usages['new_content']['reward_amount'], 0.001);
        $this->assertEqualsWithDelta(50.0, CouponRedemption::sum('reward_amount'), 0.001);
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
}
