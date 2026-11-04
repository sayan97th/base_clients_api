<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /api/cart/checkout/deferred ("Request Invoice")
 *
 * Verifies that a promo code is honored on orders and generated invoices
 * under $500.00, and that only a coupon's own minimum_purchase_amount can
 * block it. Coupons that fail validation at checkout are skipped, so the
 * order is charged at full price instead of failing.
 */
class DeferredCheckoutCouponMinimumTest extends TestCase
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

        $this->client = User::factory()->create(['is_active' => true]);
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
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

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

    /** One DR 50+ link at $400, the exact cart from the client report. */
    private function checkoutSingleLink(array $coupon_ids, float $total_amount): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout/deferred', [
            'deferred_payment'           => true,
            'total_amount'               => $total_amount,
            'session_id'                 => (string) Str::uuid(),
            'order_title'                => 'Small Promo Order',
            'order_notes'                => null,
            'coupon_ids'                 => $coupon_ids,
            'link_building_items'        => [[
                'dr_tier_id' => $this->dr_tier->id,
                'quantity'   => 1,
                'unit_price' => 400.0,
                'placements' => [[
                    'row_index'    => 0,
                    'keyword'      => 'test keyword',
                    'landing_page' => 'https://example.com/page',
                    'exact_match'  => false,
                ]],
            ]],
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
        ]);
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    public function test_coupon_without_minimum_discounts_order_under_500(): void
    {
        $coupon = $this->makeCoupon();

        $response = $this->checkoutSingleLink([$coupon->id], 300.0);

        $response->assertOk();
        $this->assertEquals(300.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('link_building_orders', [
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 400.0,
            'total_amount'             => 300.0,
        ]);
        $this->assertDatabaseHas('link_building_order_coupons', [
            'coupon_id'       => $coupon->id,
            'discount_amount' => 100.0,
        ]);
    }

    public function test_generated_invoice_reflects_discount_for_order_under_500(): void
    {
        $coupon = $this->makeCoupon();

        $response = $this->checkoutSingleLink([$coupon->id], 300.0);

        $response->assertOk();
        $invoice_unique_id = $response->json('data.invoice_unique_id');
        $this->assertNotNull($invoice_unique_id);

        $this->assertDatabaseHas('invoices', [
            'unique_id'    => $invoice_unique_id,
            'total_amount' => 300.0,
            'status'       => 'unpaid',
        ]);
    }

    public function test_coupon_usage_is_counted_for_order_under_500(): void
    {
        $coupon = $this->makeCoupon();

        $this->checkoutSingleLink([$coupon->id], 300.0)->assertOk();

        $this->assertEquals(1, $coupon->fresh()->times_used);
    }

    public function test_coupon_with_own_minimum_above_cart_is_skipped_and_order_is_full_price(): void
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

    public function test_coupon_with_own_minimum_below_cart_is_applied(): void
    {
        $coupon = $this->makeCoupon([
            'code'                    => 'SMALLMIN',
            'applies_to'              => 'minimum_purchase',
            'minimum_purchase_amount' => 250.0,
        ]);

        $response = $this->checkoutSingleLink([$coupon->id], 300.0);

        $response->assertOk();
        $this->assertEquals(300.0, (float) $response->json('data.orders.0.total_amount'));
    }
}
