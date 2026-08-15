<?php

namespace Tests\Feature\Payment;

use App\Models\ContentBriefTier;
use App\Models\ContentOptimizationTier;
use App\Models\DrTier;
use App\Models\NewContentTier;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Regression coverage for the price-desync bug: the client cart can hold a
 * unit_price snapshot taken before an admin changes the tier's price, and a
 * request payload can claim any unit_price at all. Checkout must always
 * charge and persist the tier's *current* price from the database, never
 * the client-submitted value.
 */
class CartCheckoutPriceAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

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
    }

    private function mockStripe(): void
    {
        $mock = Mockery::mock(StripeService::class);

        $mock->shouldReceive('verifyPaymentIntent')->andReturn(['verified' => true]);
        $mock->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $mock->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);

        $this->app->instance(StripeService::class, $mock);
    }

    private function baseCheckoutPayload(array $overrides = []): array
    {
        return array_merge([
            'payment_method_id' => 'pi_test_card',
            'total_amount'      => 100.0,
            'session_id'        => (string) \Illuminate\Support\Str::uuid(),
            'order_title'       => 'Test Order',
            'order_notes'       => null,
            'billing' => [
                'company'     => 'Test Co',
                'address'     => '123 Main St',
                'city'        => 'Boise',
                'state'       => 'ID',
                'country'     => 'US',
                'postal_code' => '83701',
            ],
            'coupon_ids'                 => [],
            'link_building_items'        => null,
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
            'credits_amount'             => 0,
        ], $overrides);
    }

    // ─── Link Building ───────────────────────────────────────────────────────

    public function test_checkout_uses_the_dr_tiers_current_price_not_the_client_submitted_price(): void
    {
        $this->mockStripe();

        // This is the exact reported bug: card shows $475/link but the client's
        // cart still holds a stale $500 snapshot from before the admin edited
        // the tier price.
        DrTier::create([
            'id' => 'dr60', 'label' => 'DR 60+', 'min_dr' => 60, 'max_dr' => 69,
            'traffic_range' => '5k-50k', 'word_count' => 700,
            'price_per_link' => 475.0, 'is_hidden' => false, 'is_active' => true,
        ]);

        // Quantity stays below the 10-link bulk discount threshold so this
        // test isolates the price-authority behavior from that other feature.
        $payload = $this->baseCheckoutPayload([
            'total_amount'        => 2500.0, // stale client-side total (5 * $500)
            'link_building_items' => [[
                'dr_tier_id' => 'dr60',
                'quantity'   => 5,
                'unit_price' => 500.0, // stale client-submitted price
                'placements' => array_map(fn ($i) => [
                    'row_index' => $i, 'keyword' => null, 'landing_page' => null, 'exact_match' => false,
                ], range(0, 4)),
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);

        // 5 * $475 = $2,375 — the current admin price, not the stale $500 one.
        $this->assertEquals(2375.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('link_building_orders', [
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 2375.0,
            'total_amount'             => 2375.0,
        ]);

        $this->assertDatabaseHas('link_building_order_items', [
            'dr_tier_id' => 'dr60',
            'unit_price' => 475.0,
            'subtotal'   => 2375.0,
        ]);
    }

    public function test_checkout_recomputes_bulk_discount_from_the_authoritative_price(): void
    {
        $this->mockStripe();

        // A client trying to game the 10-link bulk discount by submitting a
        // near-zero unit_price must still be charged on the real $100 price.
        DrTier::create([
            'id' => 'dr30', 'label' => 'DR 30+', 'min_dr' => 30, 'max_dr' => 39,
            'traffic_range' => '1k-5k', 'word_count' => 500,
            'price_per_link' => 100.0, 'is_hidden' => false, 'is_active' => true,
        ]);

        $payload = $this->baseCheckoutPayload([
            'total_amount'        => 9.0,
            'link_building_items' => [[
                'dr_tier_id' => 'dr30',
                'quantity'   => 10,
                'unit_price' => 0.01,
                'placements' => array_map(fn ($i) => [
                    'row_index' => $i, 'keyword' => null, 'landing_page' => null, 'exact_match' => false,
                ], range(0, 9)),
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);

        // 10 * $100 = $1,000 subtotal, 10% bulk discount = $900. Not $0.09.
        $this->assertEquals(900.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('link_building_orders', [
            'subtotal_before_discount' => 1000.0,
            'total_amount'             => 900.0,
        ]);
    }

    // ─── Content Optimization ────────────────────────────────────────────────

    public function test_checkout_uses_the_content_optimization_tiers_current_price(): void
    {
        $this->mockStripe();

        ContentOptimizationTier::create([
            'id' => 'co-basic', 'label' => 'Basic', 'word_count_range' => '500-1000',
            'turnaround_days' => 5, 'price' => 200.0, 'is_active' => true,
        ]);

        $payload = $this->baseCheckoutPayload([
            'total_amount'                => 999.0,
            'content_optimization_items'  => [[
                'tier_id'    => 'co-basic',
                'quantity'   => 3,
                'unit_price' => 999.0, // bogus client-submitted price
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);
        $this->assertEquals(600.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('content_optimization_order_items', [
            'tier_id'    => 'co-basic',
            'unit_price' => 200.0,
            'subtotal'   => 600.0,
        ]);
    }

    // ─── New Content ─────────────────────────────────────────────────────────

    public function test_checkout_uses_the_new_content_tiers_current_price(): void
    {
        $this->mockStripe();

        NewContentTier::create([
            'id' => 'nc-standard', 'label' => 'Standard Article',
            'turnaround_time' => '6 Days', 'price' => 150.0, 'is_active' => true,
        ]);

        $payload = $this->baseCheckoutPayload([
            'total_amount'      => 1.0,
            'new_content_items' => [[
                'tier_id'    => 'nc-standard',
                'quantity'   => 2,
                'unit_price' => 1.0, // bogus client-submitted price
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);
        $this->assertEquals(300.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('new_content_order_items', [
            'tier_id'    => 'nc-standard',
            'unit_price' => 150.0,
            'subtotal'   => 300.0,
        ]);
    }

    // ─── Content Brief ───────────────────────────────────────────────────────

    public function test_checkout_uses_the_content_brief_tiers_current_price(): void
    {
        $this->mockStripe();

        ContentBriefTier::create([
            'id' => 'cb-standard', 'label' => 'Standard Brief',
            'turnaround_days' => 3, 'price' => 80.0, 'is_active' => true,
        ]);

        $payload = $this->baseCheckoutPayload([
            'total_amount'        => 5000.0,
            'content_brief_items' => [[
                'tier_id'    => 'cb-standard',
                'quantity'   => 5,
                'unit_price' => 1000.0, // bogus client-submitted price
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);
        $this->assertEquals(400.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('content_brief_order_items', [
            'tier_id'    => 'cb-standard',
            'unit_price' => 80.0,
            'subtotal'   => 400.0,
        ]);
    }

    // ─── Multi-product session: every order type resolves its own price ─────

    public function test_multi_product_checkout_resolves_authoritative_price_per_product_type(): void
    {
        $this->mockStripe();

        DrTier::create([
            'id' => 'dr30', 'label' => 'DR 30+', 'min_dr' => 30, 'max_dr' => 39,
            'traffic_range' => '1k-5k', 'word_count' => 500,
            'price_per_link' => 260.0, 'is_hidden' => false, 'is_active' => true,
        ]);
        ContentOptimizationTier::create([
            'id' => 'co-basic', 'label' => 'Basic', 'word_count_range' => '500-1000',
            'turnaround_days' => 5, 'price' => 200.0, 'is_active' => true,
        ]);

        $payload = $this->baseCheckoutPayload([
            'total_amount'        => 1.0,
            'link_building_items' => [[
                'dr_tier_id' => 'dr30',
                'quantity'   => 1,
                'unit_price' => 1.0,
                'placements' => [['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false]],
            ]],
            'content_optimization_items' => [[
                'tier_id'    => 'co-basic',
                'quantity'   => 1,
                'unit_price' => 1.0,
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);

        $orders   = $response->json('data.orders');
        $lb_order = collect($orders)->firstWhere('product_type', 'link_building');
        $co_order = collect($orders)->firstWhere('product_type', 'content_optimization');

        $this->assertEquals(260.0, (float) $lb_order['total_amount']);
        $this->assertEquals(200.0, (float) $co_order['total_amount']);
    }

    // ─── Regression: correctly-priced payloads still work ───────────────────

    public function test_checkout_still_succeeds_when_submitted_price_already_matches(): void
    {
        $this->mockStripe();

        DrTier::create([
            'id' => 'dr60', 'label' => 'DR 60+', 'min_dr' => 60, 'max_dr' => 69,
            'traffic_range' => '5k-50k', 'word_count' => 700,
            'price_per_link' => 475.0, 'is_hidden' => false, 'is_active' => true,
        ]);

        $payload = $this->baseCheckoutPayload([
            'total_amount'        => 475.0,
            'link_building_items' => [[
                'dr_tier_id' => 'dr60',
                'quantity'   => 1,
                'unit_price' => 475.0,
                'placements' => [['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false]],
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);
        $this->assertEquals(475.0, (float) $response->json('data.orders.0.total_amount'));
    }

    // ─── Tier removed mid-flight ─────────────────────────────────────────────

    public function test_checkout_returns_422_when_dr_tier_was_removed_after_the_client_loaded_it(): void
    {
        $this->mockStripe();

        $tier = DrTier::create([
            'id' => 'dr60', 'label' => 'DR 60+', 'min_dr' => 60, 'max_dr' => 69,
            'traffic_range' => '5k-50k', 'word_count' => 700,
            'price_per_link' => 475.0, 'is_hidden' => false, 'is_active' => true,
        ]);
        $tier->delete(); // soft delete — simulates the admin removing the tier

        $payload = $this->baseCheckoutPayload([
            'link_building_items' => [[
                'dr_tier_id' => 'dr60',
                'quantity'   => 1,
                'unit_price' => 475.0,
                'placements' => [['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false]],
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'tier_unavailable');

        $this->assertDatabaseMissing('link_building_orders', ['user_id' => $this->client->id]);
    }
}
