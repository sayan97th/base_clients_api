<?php

namespace Tests\Feature\Payment;

use App\Models\ContentOptimizationIntakeRow;
use App\Models\ContentOptimizationTier;
use App\Models\DrTier;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class CartCheckoutTest extends TestCase
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

    private function mockStripe(bool $verified = true, bool $captured = true): void
    {
        $mock = Mockery::mock(StripeService::class);

        $mock->shouldReceive('verifyPaymentIntent')
            ->andReturn($verified
                ? ['verified' => true]
                : ['verified' => false, 'message' => 'Payment intent not valid.']);

        $mock->shouldReceive('capturePaymentIntent')
            ->andReturn($captured
                ? ['success' => true]
                : ['success' => false, 'message' => 'Capture failed.']);

        $mock->shouldReceive('cancelPaymentIntent')
            ->andReturn(['success' => true, 'voided' => true]);

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

    private function linkBuildingItem(int $quantity = 1, float $unit_price = 100.0): array
    {
        return [
            'dr_tier_id' => $this->dr_tier->id,
            'quantity'   => $quantity,
            'unit_price' => $unit_price,
            'placements' => array_map(fn ($i) => [
                'row_index'    => $i + 1,
                'keyword'      => 'test keyword ' . ($i + 1),
                'landing_page' => 'https://example.com/page-' . ($i + 1),
                'exact_match'  => false,
            ], range(0, $quantity - 1)),
        ];
    }

    // ─── Card payment ────────────────────────────────────────────────────────

    public function test_successful_card_checkout_creates_order_and_transaction(): void
    {
        $this->mockStripe();

        $payload = $this->baseCheckoutPayload([
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.orders.0.product_type', 'link_building');

        $this->assertEquals(100.0, (float) $response->json('data.orders.0.total_amount'));

        $this->assertDatabaseHas('transactions', [
            'user_id'        => $this->client->id,
            'status'         => 'success',
            'payment_method' => 'credit_card',
            'amount'         => 100.0,
        ]);
    }

    public function test_checkout_applies_bulk_discount_for_10_or_more_links(): void
    {
        $this->mockStripe();

        $payload = $this->baseCheckoutPayload([
            'total_amount'        => 900.0, // 10 links * $100 - 10% bulk = $900
            'link_building_items' => [$this->linkBuildingItem(10, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);
        $this->assertEquals(900.0, (float) $response->json('data.orders.0.total_amount'));
    }

    public function test_checkout_rejects_invalid_stripe_payment_intent(): void
    {
        $this->mockStripe(verified: false);

        $payload = $this->baseCheckoutPayload([
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(402);
        $this->assertDatabaseMissing('transactions', ['status' => 'success']);
    }

    public function test_duplicate_session_id_returns_409(): void
    {
        $this->mockStripe();

        $session_id = (string) \Illuminate\Support\Str::uuid();
        $payload    = $this->baseCheckoutPayload([
            'session_id'          => $session_id,
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        // First checkout succeeds
        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload)
            ->assertStatus(200);

        // Second checkout with same session_id is rejected
        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload)
            ->assertStatus(409);
    }

    // ─── Credits payment ─────────────────────────────────────────────────────

    public function test_successful_credits_checkout_deducts_balance_atomically(): void
    {
        $this->client->update(['credit_balance' => 500.0]);

        $payload = $this->baseCheckoutPayload([
            'payment_method_id'   => 'credits_pay_100',
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);

        $this->client->refresh();
        $this->assertEquals(400.0, (float) $this->client->credit_balance);

        $this->assertDatabaseHas('transactions', [
            'user_id'        => $this->client->id,
            'status'         => 'success',
            'payment_method' => 'account_credits',
        ]);
    }

    public function test_credits_checkout_fails_when_balance_is_insufficient(): void
    {
        $this->client->update(['credit_balance' => 50.0]);

        $payload = $this->baseCheckoutPayload([
            'payment_method_id'   => 'credits_pay_200',
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_credits');

        // Balance must be unchanged
        $this->client->refresh();
        $this->assertEquals(50.0, (float) $this->client->credit_balance);

        // No orders created
        $this->assertDatabaseMissing('link_building_orders', ['user_id' => $this->client->id]);
    }

    public function test_credits_checkout_rejects_coupon_codes(): void
    {
        $this->client->update(['credit_balance' => 500.0]);

        $payload = $this->baseCheckoutPayload([
            'payment_method_id'   => 'credits_pay_100',
            'coupon_ids'          => ['some-coupon-id'],
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(422);
    }

    // ─── Hybrid payment ──────────────────────────────────────────────────────

    public function test_hybrid_checkout_deducts_credits_and_charges_card(): void
    {
        $this->client->update(['credit_balance' => 50.0]);
        $this->mockStripe();

        $payload = $this->baseCheckoutPayload([
            'payment_method_id'   => 'pi_hybrid_test',
            'credits_amount'      => 50.0,
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);

        $this->client->refresh();
        $this->assertEquals(0.0, (float) $this->client->credit_balance);

        $this->assertDatabaseHas('transactions', [
            'user_id'        => $this->client->id,
            'status'         => 'success',
            'payment_method' => 'hybrid',
        ]);
    }

    public function test_hybrid_checkout_skips_bulk_discount(): void
    {
        // 10 links would normally trigger the 10% bulk discount ($1000 → $900),
        // but because credits are applied to the order no discount may be granted.
        $this->client->update(['credit_balance' => 100.0]);
        $this->mockStripe();

        $payload = $this->baseCheckoutPayload([
            'payment_method_id'   => 'pi_hybrid_test',
            'credits_amount'      => 50.0,
            'total_amount'        => 1000.0,
            'link_building_items' => [$this->linkBuildingItem(10, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);

        // Order total stays at the full subtotal — the bulk discount is suppressed
        // because the order is partially paid with credits.
        $this->assertEquals(1000.0, (float) $response->json('data.orders.0.total_amount'));
    }

    public function test_hybrid_checkout_rejects_coupon_codes(): void
    {
        $this->client->update(['credit_balance' => 100.0]);
        $this->mockStripe();

        $payload = $this->baseCheckoutPayload([
            'payment_method_id'   => 'pi_hybrid_test',
            'credits_amount'      => 50.0,
            'coupon_ids'          => [(string) \Illuminate\Support\Str::uuid()],
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Coupon codes cannot be applied when paying with account credits.');
    }

    public function test_hybrid_checkout_voids_stripe_when_credits_are_insufficient(): void
    {
        $this->client->update(['credit_balance' => 10.0]);
        $this->mockStripe();

        $payload = $this->baseCheckoutPayload([
            'payment_method_id'   => 'pi_hybrid_test',
            'credits_amount'      => 50.0, // more than balance
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_credits');

        // Balance must not change
        $this->client->refresh();
        $this->assertEquals(10.0, (float) $this->client->credit_balance);
    }

    // ─── Guest / unauthenticated ─────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $payload = $this->baseCheckoutPayload([
            'link_building_items' => [$this->linkBuildingItem(1, 100.0)],
        ]);

        $this->postJson('/api/cart/checkout', $payload)
            ->assertStatus(401);
    }

    // ─── Bulk paste / import regression (portal "Weave 12+ links" scenario) ──
    //
    // The portal now lets clients paste or import an entire spreadsheet of
    // keywords/pages into the intake tables. The frontend caps what it sends
    // to exactly the quantity purchased (see pasted-grid.test.ts), so these
    // tests confirm the backend accepts and persists that full, unmodified
    // batch — every row, in order, with the right data — rather than silently
    // truncating, reordering, or dropping any of it.

    public function test_checkout_persists_every_placement_from_a_large_bulk_pasted_link_building_order(): void
    {
        $this->mockStripe();

        // 12 fully-detailed placements, as produced by pasting 12 rows from a
        // spreadsheet into the Link Building intake table (the exact "Weave"
        // scenario the client described).
        $payload = $this->baseCheckoutPayload([
            'total_amount'        => 1080.0, // 12 * 100 - 10% bulk discount
            'link_building_items' => [$this->linkBuildingItem(12, 100.0)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);
        $order_id = $response->json('data.orders.0.order_id');

        $this->assertDatabaseHas('link_building_orders', [
            'id'     => $order_id,
            'status' => 'new_request',
        ]);

        $placements = LinkBuildingOrderPlacement::whereHas(
            'orderItem.order',
            fn ($q) => $q->where('id', $order_id)
        )->orderBy('row_index')->get();

        // Never grows or shrinks the batch — exactly the 12 rows submitted.
        $this->assertCount(12, $placements);

        foreach ($placements as $i => $placement) {
            $this->assertEquals('test keyword ' . ($i + 1), $placement->keyword);
            $this->assertEquals('https://example.com/page-' . ($i + 1), $placement->landing_page);
        }
    }

    public function test_checkout_persists_a_full_batch_of_bulk_imported_content_optimization_rows(): void
    {
        $this->mockStripe();

        $tier = ContentOptimizationTier::create([
            'id' => 'co-800', 'label' => '800-1,599 Words',
            'word_count_range' => '800-1599', 'turnaround_days' => 5,
            'price' => 100.0, 'is_active' => true,
        ]);

        // 7 fully-detailed rows, as produced by importing/pasting a 7-row
        // spreadsheet into the Content Optimization intake table — matching
        // the exact quantity purchased.
        $intake_rows = array_map(fn ($i) => [
            'primary_keyword'    => 'keyword ' . $i,
            'secondary_keywords' => null,
            'content_page_url'   => 'https://example.com/p' . $i,
            'notes'              => null,
        ], range(1, 7));

        $payload = $this->baseCheckoutPayload([
            'total_amount'                => 700.0,
            'link_building_items'         => null,
            'content_optimization_items'  => [[
                'tier_id' => $tier->id, 'quantity' => 7, 'unit_price' => 100.0,
                'intake_rows' => $intake_rows,
            ]],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);
        $order_id = $response->json('data.orders.0.order_id');

        $this->assertDatabaseHas('content_optimization_orders', [
            'id'     => $order_id,
            'status' => 'new_request',
        ]);

        $rows = ContentOptimizationIntakeRow::whereHas(
            'item',
            fn ($q) => $q->where('order_id', $order_id)
        )->orderBy('row_index')->get();

        // Never grows or shrinks the batch — exactly the 7 rows submitted,
        // matching the quantity purchased (this is the exact scenario the
        // client hit when a bulk paste briefly over-filled this table).
        $this->assertCount(7, $rows);

        foreach ($rows as $i => $row) {
            $this->assertEquals('keyword ' . ($i + 1), $row->primary_keyword);
            $this->assertEquals('https://example.com/p' . ($i + 1), $row->content_page_url);
        }
    }
}
