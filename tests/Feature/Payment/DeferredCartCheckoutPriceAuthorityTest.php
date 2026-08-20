<?php

namespace Tests\Feature\Payment;

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
 * Same price-desync regression coverage as CartCheckoutPriceAuthorityTest,
 * but for the "Pay Later" deferred checkout flow (POST /api/cart/checkout/
 * deferred), which never touches Stripe but must apply the exact same
 * authoritative-price rule when creating orders and invoices.
 */
class DeferredCartCheckoutPriceAuthorityTest extends TestCase
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

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
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

    // ─── Link Building ───────────────────────────────────────────────────────

    public function test_deferred_checkout_uses_the_dr_tiers_current_price_not_the_client_submitted_price(): void
    {
        DrTier::create([
            'id' => 'dr60', 'label' => 'DR 60+', 'min_dr' => 60, 'max_dr' => 69,
            'traffic_range' => '5k-50k', 'word_count' => 700,
            'price_per_link' => 475.0, 'is_hidden' => false, 'is_active' => true,
        ]);

        $payload = $this->baseDeferredPayload([
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
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);

        // 5 * $475 = $2,375 — the current admin price, not the stale $500 one.
        $this->assertEquals(2375.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('link_building_orders', [
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 2375.0,
            'total_amount'             => 2375.0,
            'status'                   => 'payment_pending',
        ]);

        $this->assertDatabaseHas('link_building_order_items', [
            'dr_tier_id' => 'dr60',
            'unit_price' => 475.0,
            'subtotal'   => 2375.0,
        ]);
    }

    // ─── Content Optimization ────────────────────────────────────────────────

    public function test_deferred_checkout_uses_the_content_optimization_tiers_current_price(): void
    {
        ContentOptimizationTier::create([
            'id' => 'co-basic', 'label' => 'Basic', 'word_count_range' => '500-1000',
            'turnaround_days' => 5, 'price' => 200.0, 'is_active' => true,
        ]);

        $payload = $this->baseDeferredPayload([
            'total_amount'               => 999.0,
            'content_optimization_items' => [[
                'tier_id'    => 'co-basic',
                'quantity'   => 3,
                'unit_price' => 999.0, // bogus client-submitted price
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);
        $this->assertEquals(600.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('content_optimization_order_items', [
            'tier_id'    => 'co-basic',
            'unit_price' => 200.0,
            'subtotal'   => 600.0,
        ]);
    }

    // ─── New Content ─────────────────────────────────────────────────────────

    public function test_deferred_checkout_uses_the_new_content_tiers_current_price(): void
    {
        NewContentTier::create([
            'id' => 'nc-standard', 'label' => 'Standard Article',
            'turnaround_time' => '6 Days', 'price' => 150.0, 'is_active' => true,
        ]);

        $payload = $this->baseDeferredPayload([
            'total_amount'      => 1.0,
            'new_content_items' => [[
                'tier_id'    => 'nc-standard',
                'quantity'   => 2,
                'unit_price' => 1.0, // bogus client-submitted price
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);
        $this->assertEquals(300.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('new_content_order_items', [
            'tier_id'    => 'nc-standard',
            'unit_price' => 150.0,
            'subtotal'   => 300.0,
        ]);
    }

    // ─── Content Brief ───────────────────────────────────────────────────────

    public function test_deferred_checkout_uses_the_content_brief_tiers_current_price(): void
    {
        ContentBriefTier::create([
            'id' => 'cb-standard', 'label' => 'Standard Brief',
            'turnaround_days' => 3, 'price' => 80.0, 'is_active' => true,
        ]);

        $payload = $this->baseDeferredPayload([
            'total_amount'        => 5000.0,
            'content_brief_items' => [[
                'tier_id'    => 'cb-standard',
                'quantity'   => 5,
                'unit_price' => 1000.0, // bogus client-submitted price
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);
        $this->assertEquals(400.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('content_brief_order_items', [
            'tier_id'    => 'cb-standard',
            'unit_price' => 80.0,
            'subtotal'   => 400.0,
        ]);
    }

    // ─── Invoice reflects the authoritative price, not the client's ─────────

    public function test_deferred_checkout_invoice_total_reflects_the_authoritative_price(): void
    {
        DrTier::create([
            'id' => 'dr60', 'label' => 'DR 60+', 'min_dr' => 60, 'max_dr' => 69,
            'traffic_range' => '5k-50k', 'word_count' => 700,
            'price_per_link' => 475.0, 'is_hidden' => false, 'is_active' => true,
        ]);

        $payload = $this->baseDeferredPayload([
            'total_amount'        => 5000.0, // stale total the client computed with the wrong price
            'link_building_items' => [[
                'dr_tier_id' => 'dr60',
                'quantity'   => 5,
                'unit_price' => 1000.0, // bogus client-submitted price
                'placements' => array_map(fn ($i) => [
                    'row_index' => $i, 'keyword' => null, 'landing_page' => null, 'exact_match' => false,
                ], range(0, 4)),
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);

        $invoice_unique_id = $response->json('data.invoice_unique_id');
        $this->assertNotNull($invoice_unique_id);

        $this->assertDatabaseHas('invoices', [
            'unique_id'    => $invoice_unique_id,
            'total_amount' => 2375.0, // 5 * $475, not 5 * $1000
            'status'       => 'unpaid',
        ]);
    }

    // ─── Tier removed mid-flight ─────────────────────────────────────────────

    public function test_deferred_checkout_returns_422_when_dr_tier_was_removed_after_the_client_loaded_it(): void
    {
        $tier = DrTier::create([
            'id' => 'dr60', 'label' => 'DR 60+', 'min_dr' => 60, 'max_dr' => 69,
            'traffic_range' => '5k-50k', 'word_count' => 700,
            'price_per_link' => 475.0, 'is_hidden' => false, 'is_active' => true,
        ]);
        $tier->delete(); // soft delete — simulates the admin removing the tier

        $payload = $this->baseDeferredPayload([
            'link_building_items' => [[
                'dr_tier_id' => 'dr60',
                'quantity'   => 1,
                'unit_price' => 475.0,
                'placements' => [['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false]],
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'tier_unavailable');

        $this->assertDatabaseMissing('link_building_orders', ['user_id' => $this->client->id]);
    }
}
