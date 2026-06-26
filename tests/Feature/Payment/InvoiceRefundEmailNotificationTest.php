<?php

namespace Tests\Feature\Payment;

use App\Jobs\SendAdminInvoiceRefundedNotificationJob;
use App\Jobs\SendClientInvoiceRefundedNotificationJob;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Verifies that refund and partial-refund actions dispatch the expected
 * email notification jobs with the correct parameters.
 *
 * Rules:
 *   1. Admin job — always dispatched on every refund / partial refund.
 *   2. Client job — dispatched only when send_client_notification=true (the default).
 *   3. Client job — suppressed when send_client_notification=false.
 *   4. History entry — created when the client notification is sent.
 *   5. Full-refund vs partial-refund flag is forwarded correctly to both jobs.
 *   6. Refund amounts in the jobs match what was actually refunded this action.
 */
class InvoiceRefundEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client']);

        $this->admin  = User::factory()->create(['is_active' => true]);
        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 0]);

        $this->admin->assignRole('admin');
        $this->client->assignRole('client');
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'unique_id'       => strtoupper(bin2hex(random_bytes(4))),
            'invoice_number'  => 'BSM-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id'         => $this->client->id,
            'status'          => 'paid',
            'payment_method'  => 'Account Balance',
            'currency_type'   => 'usd',
            'subtotal_amount' => 500.0,
            'discount_amount' => 0.0,
            'total_amount'    => 500.0,
            'credit_amount'   => 500.0,
            'date_issued'     => now(),
            'date_paid'       => now(),
        ], $overrides));
    }

    private function mockStripeSuccess(): void
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('refundPaymentIntent')
            ->andReturn(['success' => true, 'refund_id' => 're_test_email_001']);
        $this->app->instance(StripeService::class, $mock);
    }

    private function postRefund(Invoice $invoice, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->postJson(
                "/api/admin/invoices/{$invoice->id}/refund",
                array_merge(['confirmation' => true], $extra)
            );
    }

    private function postPartialRefund(Invoice $invoice, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->postJson(
                "/api/admin/invoices/{$invoice->id}/partial-refund",
                array_merge(['confirmation' => true], $extra)
            );
    }

    // ─── Full refund — admin notification ─────────────────────────────────────

    public function test_full_refund_always_dispatches_admin_notification_job(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice();

        $this->postRefund($invoice)->assertOk();

        Bus::assertDispatched(SendAdminInvoiceRefundedNotificationJob::class);
    }

    public function test_full_refund_admin_job_carries_correct_refund_amount(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 300.0, 'credit_amount' => 300.0]);

        $this->postRefund($invoice)->assertOk();

        Bus::assertDispatched(
            SendAdminInvoiceRefundedNotificationJob::class,
            fn (SendAdminInvoiceRefundedNotificationJob $job) =>
                $job->refund_amount === 300.0 && $job->is_full_refund === true
        );
    }

    public function test_full_refund_admin_job_is_dispatched_even_when_client_notification_suppressed(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice();

        $this->postRefund($invoice, ['send_client_notification' => false])->assertOk();

        Bus::assertDispatched(SendAdminInvoiceRefundedNotificationJob::class);
    }

    // ─── Full refund — client notification ────────────────────────────────────

    public function test_full_refund_dispatches_client_notification_by_default(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice();

        $this->postRefund($invoice)->assertOk();

        Bus::assertDispatched(SendClientInvoiceRefundedNotificationJob::class);
    }

    public function test_full_refund_dispatches_client_notification_when_flag_is_true(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice();

        $this->postRefund($invoice, ['send_client_notification' => true])->assertOk();

        Bus::assertDispatched(SendClientInvoiceRefundedNotificationJob::class);
    }

    public function test_full_refund_suppresses_client_notification_when_flag_is_false(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice();

        $this->postRefund($invoice, ['send_client_notification' => false])->assertOk();

        Bus::assertNotDispatched(SendClientInvoiceRefundedNotificationJob::class);
    }

    public function test_full_refund_client_job_carries_correct_refund_amount_and_full_flag(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 400.0, 'credit_amount' => 400.0]);

        $this->postRefund($invoice)->assertOk();

        Bus::assertDispatched(
            SendClientInvoiceRefundedNotificationJob::class,
            fn (SendClientInvoiceRefundedNotificationJob $job) =>
                $job->refund_amount === 400.0
                && $job->is_full_refund === true
                && $job->total_refunded === 400.0
        );
    }

    public function test_full_refund_client_job_includes_correct_credit_and_card_breakdown(): void
    {
        Bus::fake();
        $this->mockStripeSuccess();

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 200.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_mix',
        ]);

        $this->postRefund($invoice)->assertOk();

        Bus::assertDispatched(
            SendClientInvoiceRefundedNotificationJob::class,
            fn (SendClientInvoiceRefundedNotificationJob $job) =>
                $job->credit_refund === 200.0 && $job->card_refund === 300.0
        );
    }

    // ─── Full refund — history entry for notification ─────────────────────────

    public function test_full_refund_creates_notification_history_entry_when_client_is_notified(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice();

        $this->postRefund($invoice)->assertOk();

        $this->assertDatabaseHas('invoice_history', [
            'invoice_id' => $invoice->id,
            'event'      => 'refund notification sent to client',
        ]);
    }

    public function test_full_refund_does_not_create_notification_history_entry_when_suppressed(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice();

        $this->postRefund($invoice, ['send_client_notification' => false])->assertOk();

        $this->assertDatabaseMissing('invoice_history', [
            'invoice_id' => $invoice->id,
            'event'      => 'refund notification sent to client',
        ]);
    }

    // ─── Partial refund — admin notification ──────────────────────────────────

    public function test_partial_refund_always_dispatches_admin_notification_job(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 500.0, 'credit_amount' => 500.0]);

        $this->postPartialRefund($invoice, ['refund_amount' => 200.0])->assertOk();

        Bus::assertDispatched(SendAdminInvoiceRefundedNotificationJob::class);
    }

    public function test_partial_refund_admin_job_carries_correct_amount_and_partial_flag(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 500.0, 'credit_amount' => 500.0]);

        $this->postPartialRefund($invoice, ['refund_amount' => 150.0])->assertOk();

        Bus::assertDispatched(
            SendAdminInvoiceRefundedNotificationJob::class,
            fn (SendAdminInvoiceRefundedNotificationJob $job) =>
                $job->refund_amount === 150.0
                && $job->is_full_refund === false
                && $job->total_refunded === 150.0
        );
    }

    public function test_partial_refund_admin_job_is_always_dispatched_even_when_client_suppressed(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 500.0, 'credit_amount' => 500.0]);

        $this->postPartialRefund($invoice, [
            'refund_amount'            => 100.0,
            'send_client_notification' => false,
        ])->assertOk();

        Bus::assertDispatched(SendAdminInvoiceRefundedNotificationJob::class);
    }

    // ─── Partial refund — client notification ─────────────────────────────────

    public function test_partial_refund_dispatches_client_notification_by_default(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 500.0, 'credit_amount' => 500.0]);

        $this->postPartialRefund($invoice, ['refund_amount' => 100.0])->assertOk();

        Bus::assertDispatched(SendClientInvoiceRefundedNotificationJob::class);
    }

    public function test_partial_refund_suppresses_client_notification_when_flag_is_false(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 500.0, 'credit_amount' => 500.0]);

        $this->postPartialRefund($invoice, [
            'refund_amount'            => 100.0,
            'send_client_notification' => false,
        ])->assertOk();

        Bus::assertNotDispatched(SendClientInvoiceRefundedNotificationJob::class);
    }

    public function test_partial_refund_client_job_carries_this_actions_refund_amount(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 500.0, 'credit_amount' => 500.0]);

        $this->postPartialRefund($invoice, ['refund_amount' => 120.0])->assertOk();

        Bus::assertDispatched(
            SendClientInvoiceRefundedNotificationJob::class,
            fn (SendClientInvoiceRefundedNotificationJob $job) =>
                $job->refund_amount === 120.0
                && $job->is_full_refund === false
        );
    }

    public function test_partial_refund_client_job_total_refunded_accumulates_correctly(): void
    {
        Bus::fake();

        // First partial refund: 200 already on the record
        $invoice = $this->makeInvoice([
            'total_amount'   => 500.0,
            'credit_amount'  => 500.0,
            'status'         => 'partial_refund',
            'refund_amount'  => 200.0,
        ]);

        $this->postPartialRefund($invoice, ['refund_amount' => 100.0])->assertOk();

        Bus::assertDispatched(
            SendClientInvoiceRefundedNotificationJob::class,
            fn (SendClientInvoiceRefundedNotificationJob $job) =>
                $job->refund_amount === 100.0
                && $job->total_refunded === 300.0
        );
    }

    // ─── Partial refund that reaches the total — escalation to is_full_refund ──

    public function test_partial_refund_that_fully_refunds_sets_is_full_refund_flag_in_jobs(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 500.0, 'credit_amount' => 500.0]);

        $this->postPartialRefund($invoice, ['refund_amount' => 500.0])
            ->assertOk()
            ->assertJsonPath('status', 'refund');

        Bus::assertDispatched(
            SendAdminInvoiceRefundedNotificationJob::class,
            fn (SendAdminInvoiceRefundedNotificationJob $job) => $job->is_full_refund === true
        );

        Bus::assertDispatched(
            SendClientInvoiceRefundedNotificationJob::class,
            fn (SendClientInvoiceRefundedNotificationJob $job) => $job->is_full_refund === true
        );
    }

    // ─── Partial refund — history entry for notification ──────────────────────

    public function test_partial_refund_creates_notification_history_entry_when_client_notified(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 500.0, 'credit_amount' => 500.0]);

        $this->postPartialRefund($invoice, ['refund_amount' => 100.0])->assertOk();

        $this->assertDatabaseHas('invoice_history', [
            'invoice_id' => $invoice->id,
            'event'      => 'refund notification sent to client',
        ]);
    }

    public function test_partial_refund_no_notification_history_when_client_suppressed(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice(['total_amount' => 500.0, 'credit_amount' => 500.0]);

        $this->postPartialRefund($invoice, [
            'refund_amount'            => 100.0,
            'send_client_notification' => false,
        ])->assertOk();

        $this->assertDatabaseMissing('invoice_history', [
            'invoice_id' => $invoice->id,
            'event'      => 'refund notification sent to client',
        ]);
    }

    // ─── Admin job carries actor_name ─────────────────────────────────────────

    public function test_admin_job_carries_the_acting_admins_name(): void
    {
        Bus::fake();

        $this->admin->update(['first_name' => 'Super', 'last_name' => 'Admin']);
        $invoice = $this->makeInvoice();

        $this->postRefund($invoice)->assertOk();

        Bus::assertDispatched(
            SendAdminInvoiceRefundedNotificationJob::class,
            fn (SendAdminInvoiceRefundedNotificationJob $job) =>
                str_contains($job->actor_name, 'Super') || str_contains($job->actor_name, 'Admin')
        );
    }

    // ─── Admin job carries invoice_id ─────────────────────────────────────────

    public function test_admin_job_carries_the_correct_invoice_id(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice();

        $this->postRefund($invoice)->assertOk();

        Bus::assertDispatched(
            SendAdminInvoiceRefundedNotificationJob::class,
            fn (SendAdminInvoiceRefundedNotificationJob $job) =>
                $job->invoice_id === (string) $invoice->id
        );
    }

    public function test_client_job_carries_the_correct_invoice_id(): void
    {
        Bus::fake();

        $invoice = $this->makeInvoice();

        $this->postRefund($invoice)->assertOk();

        Bus::assertDispatched(
            SendClientInvoiceRefundedNotificationJob::class,
            fn (SendClientInvoiceRefundedNotificationJob $job) =>
                $job->invoice_id === (string) $invoice->id
        );
    }
}
