<?php

namespace Tests\Feature\Payment;

use App\Events\PaymentCompleted;
use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Mail\PaymentSuccessfulEmail;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class AuthenticatedInvoicePayTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $other_client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Regular client']);
        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin']);

        $this->client       = User::factory()->create(['is_active' => true]);
        $this->other_client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
        $this->other_client->assignRole('client');
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'unique_id'        => strtoupper(bin2hex(random_bytes(4))),
            'invoice_number'   => 'BSM-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id'          => $this->client->id,
            'status'           => 'unpaid',
            'currency_type'    => 'usd',
            'subtotal_amount'  => 500.0,
            'discount_amount'  => 0.0,
            'total_amount'     => 500.0,
            'credit_amount'    => 0.0,
            'sharing_enabled'  => false,
            'date_issued'      => now(),
            'date_due'         => now()->addDays(30),
        ], $overrides));
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

    private function authHeader(): array
    {
        return ['Authorization' => 'Bearer dummy-token'];
    }

    // ─── Unauthenticated ─────────────────────────────────────────────────────

    public function test_unauthenticated_request_with_auth_header_but_no_valid_token_returns_401(): void
    {
        $invoice = $this->makeInvoice();

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_method' => 'account_balance',
        ], ['Authorization' => 'Bearer invalid-token'])
            ->assertStatus(401);
    }

    // ─── Invoice ownership ───────────────────────────────────────────────────

    public function test_paying_another_clients_invoice_returns_403(): void
    {
        Mail::fake();
        Bus::fake();
        $invoice = $this->makeInvoice(['user_id' => $this->other_client->id]);

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(403);
    }

    public function test_paying_nonexistent_invoice_returns_404(): void
    {
        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson('/api/invoices/NOPE/pay', [
                'payment_method' => 'account_balance',
            ])->assertStatus(404);
    }

    // ─── Non-payable statuses ────────────────────────────────────────────────

    public function test_paying_already_paid_invoice_returns_400(): void
    {
        $invoice = $this->makeInvoice(['status' => 'paid', 'date_paid' => now()]);

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(400);
    }

    public function test_paying_void_invoice_returns_400(): void
    {
        $invoice = $this->makeInvoice(['status' => 'void']);

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(400);
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_missing_payment_method_returns_422(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_invalid_payment_method_value_returns_422(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'crypto',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_credit_card_payment_without_payment_intent_id_returns_422(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'credit_card',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['payment_intent_id']);
    }

    // ─── Account balance payment ─────────────────────────────────────────────

    public function test_account_balance_payment_returns_200_with_invoice_data(): void
    {
        Mail::fake();
        Bus::fake();
        $invoice = $this->makeInvoice();

        $response = $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Invoice paid successfully.')
            ->assertJsonStructure(['data', 'message']);
    }

    public function test_account_balance_payment_marks_invoice_as_paid(): void
    {
        Mail::fake();
        Bus::fake();
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(200);

        $this->assertDatabaseHas('invoices', [
            'id'             => $invoice->id,
            'status'         => 'paid',
            'payment_method' => 'Account Balance',
        ]);
    }

    public function test_account_balance_payment_records_invoice_history(): void
    {
        Mail::fake();
        Bus::fake();
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(200);

        $this->assertDatabaseHas('invoice_history', [
            'invoice_id' => $invoice->id,
            'event'      => 'invoice_paid',
            'actor_type' => 'client',
        ]);
    }

    public function test_account_balance_payment_creates_transaction_record(): void
    {
        Mail::fake();
        Bus::fake();
        $invoice = $this->makeInvoice(['total_amount' => 750.0]);

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(200);

        $this->assertDatabaseHas('transactions', [
            'user_id'        => $this->client->id,
            'type'           => 'credit_payment',
            'status'         => 'success',
            'amount'         => 750.0,
            'payment_method' => 'account_credits',
        ]);
    }

    public function test_overdue_invoice_can_be_paid_via_account_balance(): void
    {
        Mail::fake();
        Bus::fake();
        $invoice = $this->makeInvoice(['status' => 'overdue']);

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(200);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
    }

    // ─── Credit card payment ──────────────────────────────────────────────────

    public function test_credit_card_payment_with_valid_stripe_intent_returns_200(): void
    {
        Mail::fake();
        Bus::fake();
        $this->mockStripe();
        $invoice = $this->makeInvoice();

        $response = $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method'    => 'credit_card',
                'payment_intent_id' => 'pi_test_valid_123',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Invoice paid successfully.');
    }

    public function test_credit_card_payment_marks_invoice_as_paid_with_card_method(): void
    {
        Mail::fake();
        Bus::fake();
        $this->mockStripe();
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method'    => 'credit_card',
                'payment_intent_id' => 'pi_test_valid_123',
            ])->assertStatus(200);

        $this->assertDatabaseHas('invoices', [
            'id'             => $invoice->id,
            'status'         => 'paid',
            'payment_method' => 'Credit Card',
        ]);
    }

    public function test_credit_card_payment_creates_purchase_transaction(): void
    {
        Mail::fake();
        Bus::fake();
        $this->mockStripe();
        $invoice = $this->makeInvoice(['total_amount' => 350.0]);

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method'    => 'credit_card',
                'payment_intent_id' => 'pi_test_valid_123',
            ])->assertStatus(200);

        $this->assertDatabaseHas('transactions', [
            'user_id'        => $this->client->id,
            'type'           => 'purchase',
            'status'         => 'success',
            'amount'         => 350.0,
            'payment_method' => 'credit_card',
        ]);
    }

    public function test_credit_card_payment_records_invoice_history(): void
    {
        Mail::fake();
        Bus::fake();
        $this->mockStripe();
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method'    => 'credit_card',
                'payment_intent_id' => 'pi_test_valid_123',
            ])->assertStatus(200);

        $this->assertDatabaseHas('invoice_history', [
            'invoice_id' => $invoice->id,
            'event'      => 'invoice_paid',
        ]);
    }

    public function test_stripe_verification_failure_returns_402(): void
    {
        Bus::fake();
        $this->mockStripe(verified: false);
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method'    => 'credit_card',
                'payment_intent_id' => 'pi_test_bad',
            ])->assertStatus(402);
    }

    public function test_failed_stripe_verification_does_not_mark_invoice_paid(): void
    {
        Bus::fake();
        $this->mockStripe(verified: false);
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method'    => 'credit_card',
                'payment_intent_id' => 'pi_test_bad',
            ])->assertStatus(402);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'unpaid']);
    }

    // ─── Response data ────────────────────────────────────────────────────────

    public function test_payment_response_includes_invoice_number_and_status(): void
    {
        Mail::fake();
        Bus::fake();
        $invoice = $this->makeInvoice();

        $response = $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.invoice_number', $invoice->invoice_number);
    }

    // ─── Notifications ────────────────────────────────────────────────────────

    public function test_account_balance_payment_dispatches_admin_notification_job(): void
    {
        Mail::fake();
        Bus::fake();
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(200);

        Bus::assertDispatched(SendAdminInvoicePaidNotificationJob::class);
    }

    public function test_account_balance_payment_queues_client_confirmation_email(): void
    {
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class]);
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(200);

        Mail::assertQueued(PaymentSuccessfulEmail::class, function (PaymentSuccessfulEmail $mail) {
            return $mail->hasTo($this->client->email);
        });
    }

    public function test_account_balance_payment_fires_payment_completed_event_for_each_admin(): void
    {
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class]);
        Event::fake([PaymentCompleted::class]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(200);

        Event::assertDispatched(PaymentCompleted::class);
    }

    public function test_failed_payment_does_not_dispatch_notifications(): void
    {
        Mail::fake();
        Bus::fake();
        $invoice = $this->makeInvoice(['status' => 'paid', 'date_paid' => now()]);

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])->assertStatus(400);

        Bus::assertNothingDispatched();
        Mail::assertNothingQueued();
    }
}
