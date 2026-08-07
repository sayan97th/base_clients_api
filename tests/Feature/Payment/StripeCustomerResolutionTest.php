<?php

namespace Tests\Feature\Payment;

use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Regression coverage for the divergent-Stripe-Customer bug: the frontend used
 * to resolve its own Stripe Customer (by searching Stripe for the user's email
 * directly from a Next.js API route) instead of going through the backend,
 * which meant a SetupIntent could end up attached to a different Customer than
 * the one `PaymentProfileController::store()` later resolves via
 * `users.stripe_customer_id`. Every payment-method save then failed with a
 * customer mismatch, and any retry hit a stale, already-confirmed SetupIntent.
 *
 * These tests pin down that both /api/stripe/setup-intent and the new
 * /api/stripe/customer endpoint resolve the Stripe Customer through the same
 * `StripeService::findOrCreateCustomer()` call, so any frontend flow that
 * needs a customer (the dedicated "Add Payment Method" page or the checkout
 * "save this card" option) is guaranteed to agree with what
 * PaymentProfileController expects.
 */
class StripeCustomerResolutionTest extends TestCase
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

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
    }

    private function mockStripe(): Mockery\MockInterface
    {
        $mock = Mockery::mock(StripeService::class);
        $this->app->instance(StripeService::class, $mock);

        return $mock;
    }

    // ─── /api/stripe/setup-intent ───────────────────────────────────────────────

    public function test_setup_intent_resolves_the_customer_before_creating_the_intent(): void
    {
        $mock = $this->mockStripe();

        $mock->shouldReceive('findOrCreateCustomer')
            ->once()
            ->andReturn(['success' => true, 'customer_id' => 'cus_resolved']);

        $mock->shouldReceive('createSetupIntent')
            ->once()
            ->with('cus_resolved')
            ->andReturn(['success' => true, 'client_secret' => 'seti_abc_secret']);

        $response = $this->actingAs($this->client, 'api')->postJson('/api/stripe/setup-intent');

        $response->assertStatus(200)->assertJsonPath('client_secret', 'seti_abc_secret');
    }

    public function test_setup_intent_returns_500_when_customer_resolution_fails(): void
    {
        $mock = $this->mockStripe();
        $mock->shouldReceive('findOrCreateCustomer')->once()->andReturn(['success' => false]);
        $mock->shouldNotReceive('createSetupIntent');

        $this->actingAs($this->client, 'api')
            ->postJson('/api/stripe/setup-intent')
            ->assertStatus(500);
    }

    public function test_setup_intent_requires_authentication(): void
    {
        $this->postJson('/api/stripe/setup-intent')->assertStatus(401);
    }

    // ─── /api/stripe/customer ───────────────────────────────────────────────────

    public function test_resolve_customer_returns_the_stripe_customer_id(): void
    {
        $mock = $this->mockStripe();
        $mock->shouldReceive('findOrCreateCustomer')
            ->once()
            ->andReturn(['success' => true, 'customer_id' => 'cus_resolved']);

        $response = $this->actingAs($this->client, 'api')->postJson('/api/stripe/customer');

        $response->assertStatus(200)->assertJsonPath('stripe_customer_id', 'cus_resolved');
    }

    public function test_resolve_customer_returns_500_on_failure(): void
    {
        $mock = $this->mockStripe();
        $mock->shouldReceive('findOrCreateCustomer')->once()->andReturn(['success' => false]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/stripe/customer')
            ->assertStatus(500);
    }

    public function test_resolve_customer_requires_authentication(): void
    {
        $this->postJson('/api/stripe/customer')->assertStatus(401);
    }

    // ─── Core regression: no divergent customer creation ───────────────────────

    public function test_setup_intent_and_resolve_customer_agree_on_the_same_customer_for_the_same_user(): void
    {
        // findOrCreateCustomer persists the id on the user the first time it runs
        // and returns the persisted id on every subsequent call — exactly what
        // the real StripeService::findOrCreateCustomer does against
        // users.stripe_customer_id. Both endpoints must resolve to this value;
        // if either one created its own Customer instead, they would disagree.
        $mock = $this->mockStripe();
        $mock->shouldReceive('findOrCreateCustomer')
            ->twice()
            ->andReturn(['success' => true, 'customer_id' => 'cus_single_source_of_truth']);
        $mock->shouldReceive('createSetupIntent')
            ->once()
            ->with('cus_single_source_of_truth')
            ->andReturn(['success' => true, 'client_secret' => 'seti_secret']);

        $setup_intent_response = $this->actingAs($this->client, 'api')
            ->postJson('/api/stripe/setup-intent');

        $resolve_customer_response = $this->actingAs($this->client, 'api')
            ->postJson('/api/stripe/customer');

        $setup_intent_response->assertStatus(200);
        $resolve_customer_response->assertStatus(200)
            ->assertJsonPath('stripe_customer_id', 'cus_single_source_of_truth');
    }

    // ─── /api/stripe/create-payment-intent still works for saved-card charges ──

    public function test_create_payment_intent_resolves_the_customer_for_a_saved_card_charge(): void
    {
        $mock = $this->mockStripe();
        $mock->shouldReceive('findOrCreateCustomer')
            ->once()
            ->andReturn(['success' => true, 'customer_id' => 'cus_resolved']);
        $mock->shouldReceive('createPaymentIntent')
            ->once()
            ->andReturn(['success' => true, 'client_secret' => 'pi_secret', 'payment_intent_id' => 'pi_123']);

        $response = $this->actingAs($this->client, 'api')->postJson('/api/stripe/create-payment-intent', [
            'amount_cents'             => 5000,
            'stripe_payment_method_id' => 'pm_saved',
        ]);

        $response->assertStatus(200)->assertJsonPath('payment_intent_id', 'pi_123');
    }
}
