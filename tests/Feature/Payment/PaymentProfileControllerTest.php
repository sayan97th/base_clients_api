<?php

namespace Tests\Feature\Payment;

use App\Models\PaymentProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Regression coverage for the "card verified by Stripe but never saved" bug.
 *
 * Root cause: the frontend used to resolve a Stripe Customer independently of
 * `users.stripe_customer_id` (see the Next.js /api/stripe/setup-intent route),
 * so the PaymentMethod ended up attached to a Customer this controller did not
 * recognize, and store() rejected the save with a 409 even though Stripe had
 * already confirmed the card. These tests pin the contract the frontend fix now
 * relies on: when the PaymentMethod's Stripe customer matches (or is absent and
 * gets attached to) the user's resolved customer, the save must succeed. The
 * mismatch branch is kept under test too, since it is still a legitimate guard
 * against attaching a payment method that belongs to a different account.
 */
class PaymentProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], [
            'display_name' => 'Client',
            'description'  => 'Regular client',
        ]);

        $this->client = User::factory()->create([
            'is_active'          => true,
            'stripe_customer_id' => 'cus_mine',
        ]);
        $this->client->assignRole('client');
    }

    private function mockStripe(): Mockery\MockInterface
    {
        $mock = Mockery::mock(StripeService::class);
        $this->app->instance(StripeService::class, $mock);

        return $mock;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'stripe_payment_method_id' => 'pm_test_card',
            'cardholder_name'          => 'Jane Doe',
            'is_default'               => false,
            'billing_address'          => [
                'address_line1' => '123 Main St',
                'city'          => 'Boise',
                'state'         => 'ID',
                'postal_code'   => '83701',
                'country'       => 'US',
                'company'       => 'Acme Inc',
            ],
        ], $overrides);
    }

    // ─── The exact regression scenario ─────────────────────────────────────────

    public function test_store_saves_the_card_when_the_payment_method_customer_matches_the_resolved_customer(): void
    {
        $mock = $this->mockStripe();

        $mock->shouldReceive('retrievePaymentMethod')
            ->once()
            ->with('pm_test_card')
            ->andReturn([
                'success'     => true,
                'customer_id' => 'cus_mine',
                'card'        => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => '12', 'exp_year' => '2030'],
            ]);

        $mock->shouldReceive('findOrCreateCustomer')
            ->once()
            ->andReturn(['success' => true, 'customer_id' => 'cus_mine']);

        // Customer already matches — attach must NOT be called again.
        $mock->shouldNotReceive('attachPaymentMethod');

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/payment-profiles', $this->basePayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.card_brand', 'visa')
            ->assertJsonPath('data.last_four', '4242')
            ->assertJsonPath('data.billing_address.city', 'Boise');

        $this->assertDatabaseHas('payment_profiles', [
            'user_id'                  => $this->client->id,
            'stripe_payment_method_id' => 'pm_test_card',
            'is_default'               => true, // first card is always default
        ]);
    }

    public function test_store_attaches_and_saves_when_the_payment_method_has_no_customer_yet(): void
    {
        $mock = $this->mockStripe();

        $mock->shouldReceive('retrievePaymentMethod')
            ->once()
            ->andReturn([
                'success'     => true,
                'customer_id' => null,
                'card'        => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => '01', 'exp_year' => '2031'],
            ]);

        $mock->shouldReceive('findOrCreateCustomer')
            ->once()
            ->andReturn(['success' => true, 'customer_id' => 'cus_mine']);

        $mock->shouldReceive('attachPaymentMethod')
            ->once()
            ->with('pm_test_card', 'cus_mine')
            ->andReturn(['success' => true]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/payment-profiles', $this->basePayload());

        $response->assertStatus(201);
        $this->assertDatabaseHas('payment_profiles', [
            'user_id'                  => $this->client->id,
            'stripe_payment_method_id' => 'pm_test_card',
        ]);
    }

    public function test_store_rejects_a_payment_method_attached_to_a_different_stripe_customer(): void
    {
        $mock = $this->mockStripe();

        $mock->shouldReceive('retrievePaymentMethod')
            ->once()
            ->andReturn([
                'success'     => true,
                'customer_id' => 'cus_someone_else',
                'card'        => ['brand' => 'visa', 'last4' => '1111', 'exp_month' => '05', 'exp_year' => '2029'],
            ]);

        $mock->shouldReceive('findOrCreateCustomer')
            ->once()
            ->andReturn(['success' => true, 'customer_id' => 'cus_mine']);

        $mock->shouldNotReceive('attachPaymentMethod');

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/payment-profiles', $this->basePayload());

        $response->assertStatus(409);
        $this->assertDatabaseMissing('payment_profiles', ['stripe_payment_method_id' => 'pm_test_card']);
    }

    public function test_store_returns_409_when_the_payment_method_is_already_saved_by_the_same_user(): void
    {
        PaymentProfile::create([
            'user_id'                  => $this->client->id,
            'stripe_payment_method_id' => 'pm_test_card',
            'card_brand'               => 'visa',
            'last_four'                => '4242',
            'expiry_month'             => '12',
            'expiry_year'              => '2030',
            'is_default'               => true,
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/payment-profiles', $this->basePayload());

        $response->assertStatus(409);
    }

    // ─── Field mapping / defaults ───────────────────────────────────────────────

    public function test_store_persists_billing_address_fields(): void
    {
        $mock = $this->mockStripe();
        $mock->shouldReceive('retrievePaymentMethod')->once()->andReturn([
            'success' => true, 'customer_id' => 'cus_mine',
            'card'    => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => '12', 'exp_year' => '2030'],
        ]);
        $mock->shouldReceive('findOrCreateCustomer')->once()->andReturn(['success' => true, 'customer_id' => 'cus_mine']);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/payment-profiles', $this->basePayload())
            ->assertStatus(201);

        $this->assertDatabaseHas('payment_profiles', [
            'user_id'                 => $this->client->id,
            'billing_address_line1'   => '123 Main St',
            'billing_address_city'    => 'Boise',
            'billing_address_state'   => 'ID',
            'billing_address_postal'  => '83701',
            'billing_address_country' => 'US',
            'billing_address_company' => 'Acme Inc',
        ]);
    }

    public function test_store_saves_successfully_without_a_billing_address(): void
    {
        $mock = $this->mockStripe();
        $mock->shouldReceive('retrievePaymentMethod')->once()->andReturn([
            'success' => true, 'customer_id' => 'cus_mine',
            'card'    => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => '12', 'exp_year' => '2030'],
        ]);
        $mock->shouldReceive('findOrCreateCustomer')->once()->andReturn(['success' => true, 'customer_id' => 'cus_mine']);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/payment-profiles', $this->basePayload(['billing_address' => null]));

        $response->assertStatus(201)->assertJsonPath('data.billing_address', null);
    }

    public function test_first_saved_card_is_always_marked_default_even_if_not_requested(): void
    {
        $mock = $this->mockStripe();
        $mock->shouldReceive('retrievePaymentMethod')->once()->andReturn([
            'success' => true, 'customer_id' => 'cus_mine',
            'card'    => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => '12', 'exp_year' => '2030'],
        ]);
        $mock->shouldReceive('findOrCreateCustomer')->once()->andReturn(['success' => true, 'customer_id' => 'cus_mine']);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/payment-profiles', $this->basePayload(['is_default' => false]))
            ->assertStatus(201)
            ->assertJsonPath('data.is_default', true);
    }

    public function test_marking_a_second_card_as_default_unsets_the_previous_default(): void
    {
        PaymentProfile::create([
            'user_id' => $this->client->id, 'stripe_payment_method_id' => 'pm_existing',
            'card_brand' => 'visa', 'last_four' => '1111',
            'expiry_month' => '01', 'expiry_year' => '2028', 'is_default' => true,
        ]);

        $mock = $this->mockStripe();
        $mock->shouldReceive('retrievePaymentMethod')->once()->andReturn([
            'success' => true, 'customer_id' => 'cus_mine',
            'card'    => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => '02', 'exp_year' => '2029'],
        ]);
        $mock->shouldReceive('findOrCreateCustomer')->once()->andReturn(['success' => true, 'customer_id' => 'cus_mine']);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/payment-profiles', $this->basePayload(['stripe_payment_method_id' => 'pm_new_card', 'is_default' => true]))
            ->assertStatus(201);

        $this->assertDatabaseHas('payment_profiles', ['stripe_payment_method_id' => 'pm_existing', 'is_default' => false]);
        $this->assertDatabaseHas('payment_profiles', ['stripe_payment_method_id' => 'pm_new_card', 'is_default' => true]);
    }

    public function test_store_validates_that_the_payment_method_id_starts_with_pm(): void
    {
        $this->actingAs($this->client, 'api')
            ->postJson('/api/payment-profiles', $this->basePayload(['stripe_payment_method_id' => 'not-a-pm-id']))
            ->assertStatus(422);
    }

    // ─── Listing, deletion, default switching ───────────────────────────────────

    public function test_index_lists_only_the_authenticated_users_profiles_default_first(): void
    {
        $other = User::factory()->create(['is_active' => true]);

        PaymentProfile::create([
            'user_id' => $other->id, 'stripe_payment_method_id' => 'pm_other',
            'card_brand' => 'visa', 'last_four' => '0000',
            'expiry_month' => '01', 'expiry_year' => '2028', 'is_default' => true,
        ]);
        PaymentProfile::create([
            'user_id' => $this->client->id, 'stripe_payment_method_id' => 'pm_mine_secondary',
            'card_brand' => 'visa', 'last_four' => '1111',
            'expiry_month' => '01', 'expiry_year' => '2028', 'is_default' => false,
        ]);
        PaymentProfile::create([
            'user_id' => $this->client->id, 'stripe_payment_method_id' => 'pm_mine_default',
            'card_brand' => 'mastercard', 'last_four' => '2222',
            'expiry_month' => '02', 'expiry_year' => '2029', 'is_default' => true,
        ]);

        $response = $this->actingAs($this->client, 'api')->getJson('/api/payment-profiles');

        $response->assertStatus(200)->assertJsonCount(2, 'data');
        $this->assertEquals('pm_mine_default', $response->json('data.0.stripe_payment_method_id'));
    }

    public function test_destroy_reassigns_default_to_the_most_recent_remaining_card(): void
    {
        $older = PaymentProfile::create([
            'user_id' => $this->client->id, 'stripe_payment_method_id' => 'pm_older',
            'card_brand' => 'visa', 'last_four' => '1111',
            'expiry_month' => '01', 'expiry_year' => '2028', 'is_default' => false,
            'created_at' => now()->subDay(),
        ]);
        $default = PaymentProfile::create([
            'user_id' => $this->client->id, 'stripe_payment_method_id' => 'pm_default',
            'card_brand' => 'visa', 'last_four' => '2222',
            'expiry_month' => '02', 'expiry_year' => '2029', 'is_default' => true,
        ]);

        $mock = $this->mockStripe();
        $mock->shouldReceive('detachPaymentMethod')->once()->with('pm_default');

        $this->actingAs($this->client, 'api')
            ->deleteJson("/api/payment-profiles/{$default->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('payment_profiles', ['id' => $default->id]);
        $this->assertDatabaseHas('payment_profiles', ['id' => $older->id, 'is_default' => true]);
    }

    public function test_destroy_rejects_another_users_payment_profile(): void
    {
        $other  = User::factory()->create(['is_active' => true]);
        $profile = PaymentProfile::create([
            'user_id' => $other->id, 'stripe_payment_method_id' => 'pm_not_mine',
            'card_brand' => 'visa', 'last_four' => '1111',
            'expiry_month' => '01', 'expiry_year' => '2028', 'is_default' => true,
        ]);

        $this->actingAs($this->client, 'api')
            ->deleteJson("/api/payment-profiles/{$profile->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('payment_profiles', ['id' => $profile->id]);
    }

    public function test_set_default_switches_the_default_flag_between_profiles(): void
    {
        $first = PaymentProfile::create([
            'user_id' => $this->client->id, 'stripe_payment_method_id' => 'pm_first',
            'card_brand' => 'visa', 'last_four' => '1111',
            'expiry_month' => '01', 'expiry_year' => '2028', 'is_default' => true,
        ]);
        $second = PaymentProfile::create([
            'user_id' => $this->client->id, 'stripe_payment_method_id' => 'pm_second',
            'card_brand' => 'visa', 'last_four' => '2222',
            'expiry_month' => '02', 'expiry_year' => '2029', 'is_default' => false,
        ]);

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/payment-profiles/{$second->id}/default", ['is_default' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('payment_profiles', ['id' => $first->id, 'is_default' => false]);
        $this->assertDatabaseHas('payment_profiles', ['id' => $second->id, 'is_default' => true]);
    }

    // ─── Auth guard ──────────────────────────────────────────────────────────────

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/payment-profiles', $this->basePayload())->assertStatus(401);
        $this->getJson('/api/payment-profiles')->assertStatus(401);
    }
}
