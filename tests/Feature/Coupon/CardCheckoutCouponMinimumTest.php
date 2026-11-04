<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * POST /api/cart/checkout (pay now by card)
 *
 * Mirrors DeferredCheckoutCouponMinimumTest for the immediate payment flow:
 * promo codes apply to orders under $500.00, and a coupon whose own minimum
 * is not met is skipped without consuming a use.
 */
class CardCheckoutCouponMinimumTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Role::firstOrCreate(['name' => 'client'], [
            'display_name' => 'Client',
            'description'  => 'Regular client',
        ]);

        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 0]);
        $this->client->assignRole('client');

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

        $this->mockStripe();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function mockStripe(): void
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('verifyPaymentIntent')->andReturn(['verified' => true]);
        $mock->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $mock->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);

        $this->app->instance(StripeService::class, $mock);
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'                    => 'CONF25',
            'name'                    => 'Conference 25',
            'discount_type'           => 'percentage',
            'discount_value'          => 25.0,
            'applies_to'              => 'all',
            'is_active'               => true,
            'times_used'              => 0,
            'usage_limit'             => null,
            'usage_per_user'          => null,
            'minimum_purchase_amount' => null,
            'starts_at'               => null,
            'expires_at'              => null,
            'product_types'           => null,
        ], $overrides));
    }

    private function checkoutSingleLink(array $coupon_ids, float $total_amount): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout', [
            'payment_method_id' => 'pi_test_card',
            'total_amount'      => $total_amount,
            'session_id'        => (string) Str::uuid(),
            'order_title'       => 'Small Promo Order',
            'order_notes'       => null,
            'billing'           => [
                'company'     => 'Test Co',
                'address'     => '123 Main St',
                'city'        => 'Boise',
                'state'       => 'ID',
                'country'     => 'US',
                'postal_code' => '83701',
            ],
            'coupon_ids'          => $coupon_ids,
            'link_building_items' => [[
                'dr_tier_id' => $this->dr_tier->id,
                'quantity'   => 1,
                'unit_price' => 400.0,
                'placements' => [[
                    'row_index'    => 1,
                    'keyword'      => 'test keyword',
                    'landing_page' => 'https://example.com/page',
                    'exact_match'  => false,
                ]],
            ]],
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
            'credits_amount'             => 0,
        ]);
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    public function test_coupon_without_minimum_discounts_card_order_under_500(): void
    {
        $coupon = $this->makeCoupon();

        $response = $this->checkoutSingleLink([$coupon->id], 300.0);

        $response->assertOk();
        $this->assertEquals(300.0, (float) $response->json('data.orders.0.total_amount'));
        $this->assertDatabaseHas('link_building_order_coupons', [
            'coupon_id'       => $coupon->id,
            'discount_amount' => 100.0,
        ]);
        $this->assertEquals(1, $coupon->fresh()->times_used);
    }

    public function test_coupon_with_unmet_own_minimum_is_skipped_without_consuming_a_use(): void
    {
        $coupon = $this->makeCoupon([
            'code'                    => 'BIGORDER',
            'applies_to'              => 'minimum_purchase',
            'minimum_purchase_amount' => 1000.0,
        ]);

        $response = $this->checkoutSingleLink([$coupon->id], 400.0);

        $response->assertOk();
        $this->assertEquals(400.0, (float) $response->json('data.orders.0.total_amount'));
        $this->assertDatabaseMissing('link_building_order_coupons', ['coupon_id' => $coupon->id]);
        $this->assertEquals(0, $coupon->fresh()->times_used);
    }
}
