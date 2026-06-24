<?php

namespace Tests\Feature\Credits;

use App\Mail\CreditPurchaseConfirmationMail;
use App\Models\CreditPackage;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class CreditPurchaseEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private CreditPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], [
            'display_name' => 'Client',
            'description'  => 'Regular client',
        ]);

        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 0]);
        $this->client->assignRole('client');

        $this->package = CreditPackage::create([
            'id'             => 'starter-500',
            'name'           => 'Starter 500',
            'credits'        => 500,
            'price'          => 49.99,
            'original_price' => 59.99,
            'discount_pct'   => 17,
            'description'    => '500 credits for link building orders',
            'is_popular'     => false,
            'is_active'      => true,
        ]);
    }

    private function mockStripe(bool $verified = true, bool $captured = true): void
    {
        $mock = Mockery::mock(StripeService::class);

        $mock->shouldReceive('verifyPaymentIntent')
            ->andReturn($verified
                ? ['verified' => true]
                : ['verified' => false, 'message' => 'Payment verification failed.']);

        $mock->shouldReceive('capturePaymentIntent')
            ->andReturn($captured
                ? ['success' => true]
                : ['success' => false, 'message' => 'Capture failed.']);

        $mock->shouldReceive('cancelPaymentIntent')
            ->andReturn(['success' => true]);

        $this->app->instance(StripeService::class, $mock);
    }

    private function purchasePayload(array $overrides = []): array
    {
        return array_merge([
            'package_id'        => $this->package->id,
            'credits_amount'    => 500,
            'amount_paid'       => 49.99,
            'payment_intent_id' => 'pi_test_credits_' . uniqid(),
        ], $overrides);
    }

    // ─── Confirmation email on successful purchase ────────────────────────────

    public function test_successful_credit_purchase_queues_confirmation_email_to_client(): void
    {
        Mail::fake();
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(200);

        Mail::assertQueued(CreditPurchaseConfirmationMail::class, function (CreditPurchaseConfirmationMail $mail) {
            return $mail->hasTo($this->client->email);
        });
    }

    public function test_exactly_one_confirmation_email_queued_per_credit_purchase(): void
    {
        Mail::fake();
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(200);

        Mail::assertQueued(CreditPurchaseConfirmationMail::class, 1);
    }

    public function test_credit_purchase_email_contains_correct_package_and_amount_data(): void
    {
        Mail::fake();
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload([
                'credits_amount' => 500,
                'amount_paid'    => 49.99,
            ]))
            ->assertStatus(200);

        Mail::assertQueued(CreditPurchaseConfirmationMail::class, function (CreditPurchaseConfirmationMail $mail) {
            return $mail->package_name    === $this->package->name
                && $mail->credits_amount  === 500
                && $mail->amount_paid     === 49.99
                && $mail->user->id        === $this->client->id;
        });
    }

    public function test_credit_purchase_email_reflects_updated_balance_after_purchase(): void
    {
        Mail::fake();
        $this->mockStripe();
        $this->client->update(['credit_balance' => 100]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload(['credits_amount' => 500]))
            ->assertStatus(200);

        Mail::assertQueued(CreditPurchaseConfirmationMail::class, function (CreditPurchaseConfirmationMail $mail) {
            return $mail->new_balance === 600; // 100 existing + 500 purchased
        });
    }

    // ─── No email on failure scenarios ────────────────────────────────────────

    public function test_failed_stripe_verification_does_not_queue_confirmation_email(): void
    {
        Mail::fake();
        $this->mockStripe(verified: false);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(422);

        Mail::assertNothingQueued();
    }

    public function test_duplicate_payment_intent_does_not_queue_confirmation_email(): void
    {
        Mail::fake();
        $this->mockStripe();

        $payload = $this->purchasePayload(['payment_intent_id' => 'pi_already_used_123']);

        // First purchase succeeds
        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $payload)
            ->assertStatus(200);

        Mail::assertQueued(CreditPurchaseConfirmationMail::class, 1);

        // Reset mail fake to count only the second attempt
        Mail::fake();

        // Second attempt with the same payment_intent_id is rejected
        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $payload)
            ->assertStatus(409);

        Mail::assertNothingQueued();
    }

    public function test_unauthenticated_request_cannot_trigger_credit_purchase_email(): void
    {
        Mail::fake();

        $this->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(401);

        Mail::assertNothingQueued();
    }
}
