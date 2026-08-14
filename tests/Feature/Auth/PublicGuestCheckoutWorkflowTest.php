<?php

namespace Tests\Feature\Auth;

use App\Models\DrTier;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * The public (no-login) checkout wizard in base_portal composes two existing,
 * unmodified endpoints in sequence: POST /api/auth/register immediately
 * followed by POST /api/cart/checkout, using the JWT the register response
 * returns. No backend code was written for that feature — it deliberately
 * reuses this exact composition instead of adding new public endpoints. These
 * tests exercise that composed workflow end-to-end, plus the edge cases the
 * frontend's guest flow specifically depends on.
 */
class PublicGuestCheckoutWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Role::firstOrCreate(['name' => 'client'], [
            'display_name' => 'Client',
            'description'  => 'Regular client',
        ]);

        $this->dr_tier = DrTier::create([
            'id'             => 'dr40',
            'label'          => 'DR 40+',
            'min_dr'         => 40,
            'max_dr'         => 49,
            'traffic_range'  => '10k-20k',
            'word_count'     => 500,
            'price_per_link' => 130.0,
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

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name'            => 'Guest',
            'last_name'             => 'Tester',
            'email'                 => 'guest-' . Str::random(10) . '@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
    }

    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'payment_method_id' => 'pi_test_guest_card',
            'total_amount'      => 260.0,
            'session_id'        => (string) Str::uuid(),
            'order_title'       => 'Guest Checkout Order',
            'order_notes'       => null,
            'billing' => [
                'company'     => null,
                'address'     => '123 Main St',
                'city'        => 'Boise',
                'state'       => 'ID',
                'country'     => 'US',
                'postal_code' => '83701',
            ],
            'coupon_ids'                 => [],
            'link_building_items'        => [[
                'dr_tier_id' => $this->dr_tier->id,
                'quantity'   => 2,
                'unit_price' => 130.0,
                'placements' => [
                    ['row_index' => 0, 'keyword' => 'best running shoes', 'landing_page' => 'https://example.com/shoes', 'exact_match' => false],
                    ['row_index' => 1, 'keyword' => 'trail running gear', 'landing_page' => 'https://example.com/gear', 'exact_match' => false],
                ],
            ]],
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
            'credits_amount'             => 0,
        ], $overrides);
    }

    // ─── The full composed workflow ───────────────────────────────────────────

    public function test_guest_can_register_then_immediately_checkout_using_the_returned_token(): void
    {
        $this->mockStripe();

        $email = 'guest-' . Str::random(10) . '@example.com';

        $register_response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'email' => $email,
        ]));

        $register_response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user']);

        $token = $register_response->json('access_token');
        $this->assertNotEmpty($token);

        // The "api" guard is resolved (and its request bound) as a singleton
        // the moment register() calls auth()->login($user). Forget it so the
        // next request is authenticated against the token this test sends,
        // not a stale reference to the register request. This is purely a
        // same-process test-simulation artifact — the real app has no such
        // issue since the guard is resolved fresh per real HTTP request
        // (already verified against the live dev server with a real browser).
        $this->app['auth']->forgetGuards();

        $checkout_response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/cart/checkout', $this->checkoutPayload());

        $checkout_response->assertStatus(200)
            ->assertJsonPath('data.orders.0.product_type', 'link_building');

        $this->assertEquals(260.0, (float) $checkout_response->json('data.orders.0.total_amount'));

        $user = User::where('email', $email)->firstOrFail();

        $this->assertDatabaseHas('link_building_orders', [
            'user_id' => $user->id,
            'status'  => 'new_request',
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id'        => $user->id,
            'status'         => 'success',
            'payment_method' => 'credit_card',
            'amount'         => 260.0,
        ]);

        $order = LinkBuildingOrder::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue(Invoice::where('order_id', $order->id)->exists());
    }

    public function test_registration_assigns_the_client_role_and_starts_with_zero_credit_balance(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registrationPayload());
        $response->assertStatus(200);

        $user = User::where('email', $response->json('user.email'))->firstOrFail();

        $this->assertTrue($user->hasRole('client'));
        $this->assertEquals(0.0, (float) $user->credit_balance);
        $this->assertTrue($user->is_active);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        $email = 'guest-' . Str::random(10) . '@example.com';
        User::factory()->create(['email' => $email]);

        $response = $this->postJson('/api/auth/register', $this->registrationPayload(['email' => $email]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_requires_password_confirmation_to_match(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'password_confirmation' => 'SomethingElse123!',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ─── Behavior the guest-mode frontend cart guard depends on ──────────────

    public function test_cart_endpoints_require_authentication(): void
    {
        $this->getJson('/api/cart')->assertStatus(401);
        $this->putJson('/api/cart', ['items' => []])->assertStatus(401);
        $this->deleteJson('/api/cart')->assertStatus(401);
    }

    public function test_checkout_is_rejected_without_a_token_even_with_a_valid_payload(): void
    {
        $this->mockStripe();

        $this->postJson('/api/cart/checkout', $this->checkoutPayload())
            ->assertStatus(401);
    }

    // ─── A second guest re-using the same registration email fails cleanly ───

    public function test_second_registration_attempt_with_same_email_does_not_create_a_second_user(): void
    {
        $email = 'guest-' . Str::random(10) . '@example.com';

        $this->postJson('/api/auth/register', $this->registrationPayload(['email' => $email]))
            ->assertStatus(200);

        $this->postJson('/api/auth/register', $this->registrationPayload(['email' => $email]))
            ->assertStatus(422);

        $this->assertEquals(1, User::where('email', $email)->count());
    }
}
