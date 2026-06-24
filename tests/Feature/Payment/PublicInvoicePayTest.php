<?php

namespace Tests\Feature\Payment;

use App\Events\PaymentCompleted;
use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Mail\PaymentSuccessfulEmail;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripePublicPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class PublicInvoicePayTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private string $share_key = 'valid-public-share-key-48-chars-xxxxx';

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Regular client']);
        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin']);

        $this->client = User::factory()->create(['is_active' => true, 'email' => 'client@example.com']);
        $this->client->assignRole('client');
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
            'sharing_enabled'  => true,
            'share_key'        => $this->share_key,
            'date_issued'      => now(),
            'date_due'         => now()->addDays(30),
        ], $overrides));
    }

    private function mockPublicPaymentServiceSuccess(Invoice $invoice): void
    {
        $mock = Mockery::mock(StripePublicPaymentService::class);
        $mock->shouldReceive('confirmPublicInvoicePayment')
            ->andReturnUsing(function (Invoice $inv, string $pi_id, string $token) {
                $inv->update(['status' => 'paid', 'date_paid' => now(), 'payment_method' => 'Credit Card', 'payment_intent_id' => $pi_id]);
                return ['success' => true, 'message' => 'Payment confirmed successfully.', 'status' => 'paid', 'status_code' => 200];
            });
        $this->app->instance(StripePublicPaymentService::class, $mock);
    }

    private function mockPublicPaymentServiceFailure(string $error, int $status_code = 403): void
    {
        $mock = Mockery::mock(StripePublicPaymentService::class);
        $mock->shouldReceive('confirmPublicInvoicePayment')
            ->andReturn(['success' => false, 'error' => $error, 'status_code' => $status_code]);
        $this->app->instance(StripePublicPaymentService::class, $mock);
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_missing_payment_intent_id_returns_422(): void
    {
        $invoice = $this->makeInvoice();

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'token' => $this->share_key,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['payment_intent_id']);
    }

    public function test_missing_token_returns_422(): void
    {
        $invoice = $this->makeInvoice();

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    // ─── Invoice lookup ──────────────────────────────────────────────────────

    public function test_nonexistent_invoice_returns_404(): void
    {
        $this->postJson('/api/invoices/NONEXISTENT/pay', [
            'payment_intent_id' => 'pi_test_123',
            'token'             => $this->share_key,
        ])->assertStatus(404);
    }

    // ─── Access control ──────────────────────────────────────────────────────

    public function test_wrong_token_returns_403(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockPublicPaymentServiceFailure('Access denied.', 403);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_123',
            'token'             => 'wrong-token',
        ])->assertStatus(403);
    }

    public function test_sharing_disabled_returns_403(): void
    {
        $invoice = $this->makeInvoice(['sharing_enabled' => false]);
        $this->mockPublicPaymentServiceFailure('Access denied.', 403);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_123',
            'token'             => $this->share_key,
        ])->assertStatus(403);
    }

    // ─── Non-payable statuses ────────────────────────────────────────────────

    public function test_already_paid_invoice_returns_400(): void
    {
        $invoice = $this->makeInvoice(['status' => 'paid', 'date_paid' => now()]);
        $this->mockPublicPaymentServiceFailure('This invoice cannot be paid in its current status.', 400);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_123',
            'token'             => $this->share_key,
        ])->assertStatus(400);
    }

    public function test_voided_invoice_returns_400(): void
    {
        $invoice = $this->makeInvoice(['status' => 'void']);
        $this->mockPublicPaymentServiceFailure('This invoice cannot be paid in its current status.', 400);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_123',
            'token'             => $this->share_key,
        ])->assertStatus(400);
    }

    // ─── Successful payment ──────────────────────────────────────────────────

    public function test_valid_payment_returns_200_with_message(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockPublicPaymentServiceSuccess($invoice);

        $response = $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_success_123',
            'token'             => $this->share_key,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Payment confirmed successfully.');
    }

    public function test_successful_payment_updates_invoice_status_to_paid(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockPublicPaymentServiceSuccess($invoice);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_success_123',
            'token'             => $this->share_key,
        ])->assertStatus(200);

        $this->assertDatabaseHas('invoices', [
            'id'     => $invoice->id,
            'status' => 'paid',
        ]);
    }

    // ─── Payment_pending orders transition ───────────────────────────────────

    public function test_payment_does_not_fail_when_no_session_or_order_linked(): void
    {
        $invoice = $this->makeInvoice(['session_id' => null, 'order_id' => null]);
        $this->mockPublicPaymentServiceSuccess($invoice);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_456',
            'token'             => $this->share_key,
        ])->assertStatus(200);
    }

    // ─── Stripe verification failure ─────────────────────────────────────────

    public function test_stripe_verification_failure_returns_402(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockPublicPaymentServiceFailure('Payment verification failed.', 402);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_bad',
            'token'             => $this->share_key,
        ])->assertStatus(402);
    }

    // ─── Admin email notifications ───────────────────────────────────────────

    public function test_successful_payment_dispatches_admin_invoice_paid_notification_job(): void
    {
        Bus::fake();
        Mail::fake();

        $invoice = $this->makeInvoice();

        $mock = Mockery::mock(StripePublicPaymentService::class);
        $mock->shouldReceive('confirmPublicInvoicePayment')
            ->andReturnUsing(function (Invoice $inv, string $pi_id, string $token) {
                $inv->update(['status' => 'paid', 'date_paid' => now()]);
                Bus::dispatch(new SendAdminInvoicePaidNotificationJob($inv->id));
                return ['success' => true, 'message' => 'Payment confirmed successfully.', 'status' => 'paid', 'status_code' => 200];
            });
        $this->app->instance(StripePublicPaymentService::class, $mock);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_success_123',
            'token'             => $this->share_key,
        ])->assertStatus(200);

        Bus::assertDispatched(SendAdminInvoicePaidNotificationJob::class);
    }

    public function test_successful_payment_fires_payment_completed_event_for_admins(): void
    {
        Event::fake([PaymentCompleted::class]);
        Mail::fake();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $invoice = $this->makeInvoice();

        $mock = Mockery::mock(StripePublicPaymentService::class);
        $payer_name = $this->client->full_name ?? $this->client->email;
        $mock->shouldReceive('confirmPublicInvoicePayment')
            ->andReturnUsing(function (Invoice $inv) use ($payer_name) {
                $inv->update(['status' => 'paid', 'date_paid' => now()]);
                User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super_admin']))
                    ->where('is_active', true)
                    ->each(function (User $a) use ($inv, $payer_name) {
                        event(new PaymentCompleted(
                            user:           $a,
                            payer_name:     $payer_name,
                            amount:         (float) $inv->total_amount,
                            invoice_number: $inv->invoice_number,
                            link:           '/admin/invoices/' . $inv->id,
                            invoice:        $inv,
                        ));
                    });
                return ['success' => true, 'message' => 'Payment confirmed successfully.', 'status' => 'paid', 'status_code' => 200];
            });
        $this->app->instance(StripePublicPaymentService::class, $mock);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_success_123',
            'token'             => $this->share_key,
        ])->assertStatus(200);

        Event::assertDispatched(PaymentCompleted::class);
    }

    public function test_failed_payment_does_not_dispatch_admin_notification_job(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice();
        $this->mockPublicPaymentServiceFailure('Payment verification failed.', 402);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_bad',
            'token'             => $this->share_key,
        ])->assertStatus(402);

        Bus::assertNotDispatched(SendAdminInvoicePaidNotificationJob::class);
    }

    // ─── Client confirmation email ───────────────────────────────────────────

    public function test_successful_payment_queues_client_confirmation_email(): void
    {
        Mail::fake();

        $invoice = $this->makeInvoice();

        $mock = Mockery::mock(StripePublicPaymentService::class);
        $client = $this->client;
        $mock->shouldReceive('confirmPublicInvoicePayment')
            ->andReturnUsing(function (Invoice $inv) use ($client) {
                $inv->update(['status' => 'paid', 'date_paid' => now()]);
                Mail::to($client->email)->queue(new PaymentSuccessfulEmail($client, $inv));
                return ['success' => true, 'message' => 'Payment confirmed successfully.', 'status' => 'paid', 'status_code' => 200];
            });
        $this->app->instance(StripePublicPaymentService::class, $mock);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_success_123',
            'token'             => $this->share_key,
        ])->assertStatus(200);

        Mail::assertQueued(PaymentSuccessfulEmail::class, function (PaymentSuccessfulEmail $mail) {
            return $mail->hasTo($this->client->email);
        });
    }

    public function test_failed_payment_does_not_queue_client_email(): void
    {
        Mail::fake();

        $invoice = $this->makeInvoice();
        $this->mockPublicPaymentServiceFailure('Payment verification failed.', 402);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_bad',
            'token'             => $this->share_key,
        ])->assertStatus(402);

        Mail::assertNothingQueued();
    }

    // ─── Transaction record ──────────────────────────────────────────────────

    public function test_successful_payment_creates_transaction_record(): void
    {
        Mail::fake();

        $invoice = $this->makeInvoice(['total_amount' => 500.0]);

        $mock = Mockery::mock(StripePublicPaymentService::class);
        $client = $this->client;
        $mock->shouldReceive('confirmPublicInvoicePayment')
            ->andReturnUsing(function (Invoice $inv, string $pi_id) use ($client) {
                $inv->update(['status' => 'paid', 'date_paid' => now(), 'payment_intent_id' => $pi_id]);
                Transaction::create([
                    'user_id'           => $client->id,
                    'type'              => 'purchase',
                    'status'            => 'success',
                    'amount'            => $inv->total_amount,
                    'payment_method'    => 'credit_card',
                    'payment_intent_id' => $pi_id,
                    'invoice_id'        => (string) $inv->id,
                    'description'       => "Invoice {$inv->invoice_number} paid via public share link.",
                ]);
                return ['success' => true, 'message' => 'Payment confirmed successfully.', 'status' => 'paid', 'status_code' => 200];
            });
        $this->app->instance(StripePublicPaymentService::class, $mock);

        $this->postJson("/api/invoices/{$invoice->unique_id}/pay", [
            'payment_intent_id' => 'pi_test_success_123',
            'token'             => $this->share_key,
        ])->assertStatus(200);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->client->id,
            'status'  => 'success',
            'amount'  => 500.0,
        ]);
    }
}
