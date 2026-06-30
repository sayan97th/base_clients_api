<?php

namespace Tests\Feature\Payment;

use App\Models\Coupon;
use App\Models\ContentBriefTier;
use App\Models\ContentOptimizationTier;
use App\Models\DrTier;
use App\Models\NewContentTier;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Verifies that the coupon discount is correctly applied and persisted
 * when a client chooses the "pay later" (deferred checkout) flow.
 *
 * Regression coverage: discount was previously removed from the invoice
 * after the client clicked "Pay Later" — these tests confirm the fix holds.
 */
class DeferredCheckoutCouponTest extends TestCase
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
            'id'             => 'dr30',
            'label'          => 'DR 30+',
            'min_dr'         => 30,
            'max_dr'         => 39,
            'traffic_range'  => '5k–10k',
            'word_count'     => 500,
            'price_per_link' => 100.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'                    => 'TESTCODE',
            'name'                    => 'Test Coupon',
            'discount_type'           => 'percentage',
            'discount_value'          => 10.0,
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

    private function linkBuildingItem(int $quantity = 1, float $unit_price = 100.0): array
    {
        return [
            'dr_tier_id' => $this->dr_tier->id,
            'quantity'   => $quantity,
            'unit_price' => $unit_price,
            'placements' => array_map(fn ($i) => [
                'row_index'    => $i,
                'keyword'      => 'test keyword ' . ($i + 1),
                'landing_page' => 'https://example.com/page-' . ($i + 1),
                'exact_match'  => false,
            ], range(0, $quantity - 1)),
        ];
    }

    private function baseDeferredPayload(array $overrides = []): array
    {
        return array_merge([
            'deferred_payment'           => true,
            'total_amount'               => 100.0,
            'session_id'                 => (string) \Illuminate\Support\Str::uuid(),
            'order_title'                => 'Test Pay Later Order',
            'order_notes'                => null,
            'coupon_ids'                 => [],
            'link_building_items'        => null,
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
        ], $overrides);
    }

    // ─── Core coupon discount tests ───────────────────────────────────────────

    public function test_deferred_checkout_with_percentage_coupon_applies_discount_to_order_total(): void
    {
        $coupon = $this->makeCoupon([
            'code'           => 'UNIPHORE',
            'name'           => '10% Off All',
            'discount_type'  => 'percentage',
            'discount_value' => 10.0,
        ]);

        // Subtotal: 5 links × $200 = $1,000. 10% off = $900.
        $payload = $this->baseDeferredPayload([
            'total_amount'        => 900.0,
            'coupon_ids'          => [$coupon->id],
            'link_building_items' => [$this->linkBuildingItem(5, 200.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);
        $this->assertEquals(900.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('link_building_orders', [
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 1000.0,
            'total_amount'             => 900.0,
        ]);
    }

    public function test_deferred_checkout_with_fixed_amount_coupon_applies_discount_to_order_total(): void
    {
        $coupon = $this->makeCoupon([
            'code'           => 'SAVE100',
            'name'           => '$100 Off',
            'discount_type'  => 'fixed_amount',
            'discount_value' => 100.0,
        ]);

        // Subtotal: 5 links × $200 = $1,000. Fixed $100 off = $900.
        $payload = $this->baseDeferredPayload([
            'total_amount'        => 900.0,
            'coupon_ids'          => [$coupon->id],
            'link_building_items' => [$this->linkBuildingItem(5, 200.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);
        $this->assertEquals(900.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('link_building_orders', [
            'user_id'      => $this->client->id,
            'total_amount' => 900.0,
        ]);
    }

    public function test_deferred_checkout_without_coupon_stores_full_price(): void
    {
        $payload = $this->baseDeferredPayload([
            'total_amount'        => 500.0,
            'coupon_ids'          => [],
            'link_building_items' => [$this->linkBuildingItem(5, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);
        $this->assertEquals(500.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('link_building_orders', [
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 500.0,
            'total_amount'             => 500.0,
        ]);
    }

    // ─── Coupon record persistence ─────────────────────────────────────────────

    public function test_deferred_checkout_records_coupon_in_order_coupons_table(): void
    {
        $coupon = $this->makeCoupon([
            'code'           => 'UNIPHORE',
            'discount_type'  => 'percentage',
            'discount_value' => 20.0,
        ]);

        // 3 links × $200 = $600. 20% off = $480.
        $payload = $this->baseDeferredPayload([
            'total_amount'        => 480.0,
            'coupon_ids'          => [$coupon->id],
            'link_building_items' => [$this->linkBuildingItem(3, 200.0)],
        ]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload)
            ->assertStatus(200);

        $this->assertDatabaseHas('link_building_order_coupons', [
            'coupon_id'       => $coupon->id,
            'discount_amount' => 120.0,
        ]);
    }

    public function test_deferred_checkout_increments_coupon_times_used_on_success(): void
    {
        $coupon = $this->makeCoupon([
            'code'       => 'COUNTER',
            'times_used' => 3,
        ]);

        $payload = $this->baseDeferredPayload([
            'total_amount'        => 900.0,
            'coupon_ids'          => [$coupon->id],
            'link_building_items' => [$this->linkBuildingItem(5, 200.0)],
        ]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload)
            ->assertStatus(200);

        $coupon->refresh();
        $this->assertEquals(4, $coupon->times_used);
    }

    // ─── Bulk vs coupon precedence ─────────────────────────────────────────────

    public function test_deferred_checkout_coupon_overrides_bulk_discount(): void
    {
        // 10 links at $100 = $1,000 → bulk would give 10% = $900
        // Coupon gives 15% = $850 → coupon wins (always overrides bulk)
        $coupon = $this->makeCoupon([
            'code'           => 'BIGGER',
            'discount_type'  => 'percentage',
            'discount_value' => 15.0,
        ]);

        $payload = $this->baseDeferredPayload([
            'total_amount'        => 850.0,
            'coupon_ids'          => [$coupon->id],
            'link_building_items' => [$this->linkBuildingItem(10, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);

        // Order total must reflect coupon (15% off), not bulk (10% off)
        $this->assertEquals(850.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('link_building_order_coupons', [
            'coupon_id' => $coupon->id,
        ]);
    }

    public function test_deferred_checkout_coupon_overrides_bulk_even_when_coupon_saves_less(): void
    {
        // When a coupon is explicitly submitted it always takes precedence over
        // the bulk discount — regardless of which saves more — because submitting
        // a coupon is an explicit admin override intent.
        // 10 links × $100 = $1,000. Bulk = $100 off. Coupon = 5% = $50 off.
        // Coupon wins: total = $950.
        $coupon = $this->makeCoupon([
            'code'           => 'SMALLER',
            'discount_type'  => 'percentage',
            'discount_value' => 5.0,
        ]);

        $payload = $this->baseDeferredPayload([
            'total_amount'        => 950.0,
            'coupon_ids'          => [$coupon->id],
            'link_building_items' => [$this->linkBuildingItem(10, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);
        $this->assertEquals(950.0, (float) $response->json('data.orders.0.total_amount'));
    }

    public function test_deferred_checkout_applies_bulk_discount_when_no_coupon_is_submitted(): void
    {
        // 10 links × $100 = $1,000. No coupon → bulk 10% = $900.
        $payload = $this->baseDeferredPayload([
            'total_amount'        => 900.0,
            'coupon_ids'          => [],
            'link_building_items' => [$this->linkBuildingItem(10, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);
        $this->assertEquals(900.0, (float) $response->json('data.orders.0.total_amount'));

        // No coupon records should exist
        $this->assertDatabaseMissing('link_building_order_coupons', [
            'discount_amount' => 100.0,
        ]);
    }

    // ─── Invoice total reflects coupon discount ────────────────────────────────

    public function test_deferred_checkout_invoice_total_reflects_coupon_discount(): void
    {
        $coupon = $this->makeCoupon([
            'code'           => 'INVOICE10',
            'discount_type'  => 'percentage',
            'discount_value' => 10.0,
        ]);

        // 5 links × $200 = $1,000. 10% off = $900.
        $payload = $this->baseDeferredPayload([
            'total_amount'        => 900.0,
            'coupon_ids'          => [$coupon->id],
            'link_building_items' => [$this->linkBuildingItem(5, 200.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);

        $invoice_unique_id = $response->json('data.invoice_unique_id');
        $this->assertNotNull($invoice_unique_id);

        $this->assertDatabaseHas('invoices', [
            'unique_id'    => $invoice_unique_id,
            'total_amount' => 900.0,
            'status'       => 'unpaid',
        ]);
    }

    // ─── Multi-product deferred checkout with coupon ───────────────────────────

    public function test_deferred_checkout_with_content_optimization_and_coupon_applies_discount(): void
    {
        $co_tier = ContentOptimizationTier::create([
            'id'             => 'co-basic',
            'label'          => 'Basic',
            'word_count_range' => '500-1000',
            'turnaround_days'  => 5,
            'price'          => 200.0,
            'is_active'      => true,
            'is_most_popular'=> false,
            'is_hidden'      => false,
            'sort_order'     => 1,
        ]);

        $coupon = $this->makeCoupon([
            'code'           => 'ALLPRODUCTS',
            'discount_type'  => 'percentage',
            'discount_value' => 10.0,
            'product_types'  => null,
        ]);

        // 2 optimizations × $200 = $400. 10% off = $360.
        $payload = $this->baseDeferredPayload([
            'total_amount' => 360.0,
            'coupon_ids'   => [$coupon->id],
            'content_optimization_items' => [
                [
                    'tier_id'    => $co_tier->id,
                    'quantity'   => 2,
                    'unit_price' => 200.0,
                    'intake_rows' => [],
                ],
            ],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);
        $this->assertEquals(360.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('content_optimization_orders', [
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 400.0,
            'total_amount'             => 360.0,
        ]);

        $this->assertDatabaseHas('content_optimization_order_coupons', [
            'coupon_id'       => $coupon->id,
            'discount_amount' => 40.0,
        ]);
    }

    public function test_deferred_checkout_multi_product_with_coupon_each_order_is_discounted(): void
    {
        $co_tier = ContentOptimizationTier::create([
            'id'              => 'co-standard',
            'label'           => 'Standard',
            'word_count_range'=> '1000-2000',
            'turnaround_days' => 7,
            'price'           => 300.0,
            'is_active'       => true,
            'is_most_popular' => false,
            'is_hidden'       => false,
            'sort_order'      => 2,
        ]);

        $coupon = $this->makeCoupon([
            'code'           => 'MULTIPRODUCT',
            'discount_type'  => 'percentage',
            'discount_value' => 10.0,
        ]);

        // LB: 2 links × $200 = $400 → 10% off = $360
        // CO: 1 × $300 → 10% off = $270
        // Combined total = $630
        $payload = $this->baseDeferredPayload([
            'total_amount' => 630.0,
            'coupon_ids'   => [$coupon->id],
            'link_building_items' => [$this->linkBuildingItem(2, 200.0)],
            'content_optimization_items' => [
                [
                    'tier_id'     => $co_tier->id,
                    'quantity'    => 1,
                    'unit_price'  => 300.0,
                    'intake_rows' => [],
                ],
            ],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);

        $orders = $response->json('data.orders');
        $lb_order = collect($orders)->firstWhere('product_type', 'link_building');
        $co_order = collect($orders)->firstWhere('product_type', 'content_optimization');

        $this->assertEqualsWithDelta(360.0, (float) $lb_order['total_amount'], 0.01);
        $this->assertEqualsWithDelta(270.0, (float) $co_order['total_amount'], 0.01);

        // The combined invoice total must equal the sum of discounted orders
        $invoice_unique_id = $response->json('data.invoice_unique_id');
        $this->assertDatabaseHas('invoices', [
            'unique_id'    => $invoice_unique_id,
            'total_amount' => 630.0,
        ]);
    }

    // ─── Product-type-restricted coupons ──────────────────────────────────────

    public function test_deferred_checkout_with_link_building_only_coupon_skips_other_product_types(): void
    {
        $co_tier = ContentOptimizationTier::create([
            'id'              => 'co-mini',
            'label'           => 'Mini',
            'word_count_range'=> '200-500',
            'turnaround_days' => 3,
            'price'           => 100.0,
            'is_active'       => true,
            'is_most_popular' => false,
            'is_hidden'       => false,
            'sort_order'      => 3,
        ]);

        $coupon = $this->makeCoupon([
            'code'          => 'LBONLY',
            'discount_type' => 'percentage',
            'discount_value'=> 10.0,
            'product_types' => ['link_building'],
        ]);

        // LB: 3 × $100 = $300 → 10% off = $270
        // CO: 2 × $100 = $200 → not discounted = $200
        $payload = $this->baseDeferredPayload([
            'total_amount' => 470.0,
            'coupon_ids'   => [$coupon->id],
            'link_building_items' => [$this->linkBuildingItem(3, 100.0)],
            'content_optimization_items' => [
                [
                    'tier_id'     => $co_tier->id,
                    'quantity'    => 2,
                    'unit_price'  => 100.0,
                    'intake_rows' => [],
                ],
            ],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);

        $orders = $response->json('data.orders');
        $lb_order = collect($orders)->firstWhere('product_type', 'link_building');
        $co_order = collect($orders)->firstWhere('product_type', 'content_optimization');

        $this->assertEqualsWithDelta(270.0, (float) $lb_order['total_amount'], 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $co_order['total_amount'], 0.01);
    }

    // ─── Validation and error cases ───────────────────────────────────────────

    public function test_deferred_checkout_with_nonexistent_coupon_id_returns_422(): void
    {
        $payload = $this->baseDeferredPayload([
            'total_amount'        => 100.0,
            'coupon_ids'          => ['00000000-0000-0000-0000-000000000000'],
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('link_building_orders', ['user_id' => $this->client->id]);
    }

    public function test_deferred_checkout_requires_deferred_payment_flag(): void
    {
        $payload = $this->baseDeferredPayload([
            'deferred_payment'    => false,
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload)
            ->assertStatus(422);
    }

    public function test_deferred_checkout_requires_at_least_one_item(): void
    {
        $payload = $this->baseDeferredPayload([
            'link_building_items'        => null,
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
        ]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload)
            ->assertStatus(422);
    }

    public function test_unauthenticated_deferred_checkout_is_rejected(): void
    {
        $payload = $this->baseDeferredPayload([
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $this->postJson('/api/cart/checkout/deferred', $payload)
            ->assertStatus(401);
    }

    // ─── Order status in deferred flow ────────────────────────────────────────

    public function test_deferred_checkout_sets_order_status_to_payment_pending(): void
    {
        $payload = $this->baseDeferredPayload([
            'total_amount'        => 100.0,
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload)
            ->assertStatus(200);

        $this->assertDatabaseHas('link_building_orders', [
            'user_id' => $this->client->id,
            'status'  => 'payment_pending',
        ]);
    }

    public function test_deferred_checkout_returns_invoice_unique_id(): void
    {
        $payload = $this->baseDeferredPayload([
            'total_amount'        => 100.0,
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.invoice_unique_id'));
    }
}
