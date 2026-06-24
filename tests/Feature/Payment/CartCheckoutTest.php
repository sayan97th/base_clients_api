<?php

namespace Tests\Feature\Payment;

use App\Models\DrTier;
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
}
