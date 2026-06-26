<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Covers the full-refund flow via POST /api/admin/invoices/{id}/refund.
 *
 * Tests verify:
 *   – Status transitions and guards
 *   – Credit balance restoration
 *   – Stripe refund dispatch (card, mixed, and missing-PI scenarios)
 *   – Atomic rollback when Stripe fails
 *   – Transaction records created per payment method
 *   – Invoice history entries
 *   – Confirmation field validation
 */
class InvoiceFullRefundTest extends TestCase
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
            'payment_method'  => 'Credit Card',
            'currency_type'   => 'usd',
            'subtotal_amount' => 500.0,
            'discount_amount' => 0.0,
            'total_amount'    => 500.0,
            'credit_amount'   => 0.0,
            'date_issued'     => now(),
            'date_paid'       => now(),
        ], $overrides));
    }

    private function mockStripeSuccess(?string $refund_id = 're_test_123'): void
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('refundPaymentIntent')
            ->andReturn(['success' => true, 'refund_id' => $refund_id]);
        $this->app->instance(StripeService::class, $mock);
    }

    private function mockStripeFailure(): void
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('refundPaymentIntent')
            ->andReturn(['success' => false, 'message' => 'Card declined.']);
        $this->app->instance(StripeService::class, $mock);
    }

    private function postRefund(Invoice $invoice, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/refund", array_merge(['confirmation' => true], $extra));
    }

    // ─── Status transitions ────────────────────────────────────────────────────

    public function test_full_refund_transitions_paid_invoice_to_refund_status(): void
    {
        $invoice = $this->makeInvoice(['payment_intent_id' => null, 'payment_method' => 'Account Balance', 'credit_amount' => 500.0]);

        $this->postRefund($invoice)->assertOk()->assertJsonPath('status', 'refund');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'refund']);
    }

    public function test_full_refund_sets_refund_amount_to_total(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 300.0, 'payment_method' => 'Account Balance', 'credit_amount' => 300.0]);

        $this->postRefund($invoice)->assertOk();

        $this->assertDatabaseHas('invoices', [
            'id'            => $invoice->id,
            'refund_amount' => 300.0,
        ]);
    }

    public function test_full_refund_sets_refunded_at_timestamp(): void
    {
        $invoice = $this->makeInvoice(['payment_method' => 'Account Balance', 'credit_amount' => 500.0]);

        $this->postRefund($invoice)->assertOk();

        $invoice->refresh();
        $this->assertNotNull($invoice->refunded_at);
    }

    // ─── Status guards ─────────────────────────────────────────────────────────

    public function test_refund_rejects_already_refunded_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => 'refund']);

        $this->postRefund($invoice)->assertStatus(422);
    }

    public function test_refund_rejects_unpaid_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => 'unpaid', 'date_paid' => null]);

        $this->postRefund($invoice)->assertStatus(422);
    }

    public function test_refund_rejects_void_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => 'void']);

        $this->postRefund($invoice)->assertStatus(422);
    }

    public function test_refund_rejects_overdue_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => 'overdue']);

        $this->postRefund($invoice)->assertStatus(422);
    }

    public function test_refund_on_missing_invoice_returns_404(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/invoices/00000000-0000-0000-0000-000000000000/refund', ['confirmation' => true])
            ->assertStatus(404);
    }

    // ─── Request validation ────────────────────────────────────────────────────

    public function test_refund_requires_confirmation_field(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/refund", ['confirmation' => false])
            ->assertStatus(422);
    }

    // ─── Credit balance restoration ────────────────────────────────────────────

    public function test_refund_restores_full_credit_balance_for_credit_paid_invoice(): void
    {
        $this->client->update(['credit_balance' => 0]);

        $invoice = $this->makeInvoice([
            'total_amount'   => 400.0,
            'credit_amount'  => 400.0,
            'payment_method' => 'Account Balance',
        ]);

        $this->postRefund($invoice)->assertOk();

        $this->assertEquals(400.0, (float) $this->client->fresh()->credit_balance);
    }

    public function test_refund_restores_credit_portion_for_mixed_payment(): void
    {
        $this->client->update(['credit_balance' => 0]);

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 200.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_test_mixed',
        ]);

        $this->mockStripeSuccess();

        $this->postRefund($invoice)->assertOk();

        $this->assertEquals(200.0, (float) $this->client->fresh()->credit_balance);
    }

    public function test_refund_does_not_touch_credit_balance_for_pure_card_payment(): void
    {
        $this->client->update(['credit_balance' => 50.0]);

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 0.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_test_card',
        ]);

        $this->mockStripeSuccess();

        $this->postRefund($invoice)->assertOk();

        $this->assertEquals(50.0, (float) $this->client->fresh()->credit_balance);
    }

    // ─── Stripe card refund ────────────────────────────────────────────────────

    public function test_refund_calls_stripe_for_card_payment(): void
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('refundPaymentIntent')
            ->once()
            ->with('pi_test_card', 'requested_by_customer', 50000)
            ->andReturn(['success' => true, 'refund_id' => 're_card_001']);
        $this->app->instance(StripeService::class, $mock);

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 0.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_test_card',
        ]);

        $this->postRefund($invoice)->assertOk()->assertJsonPath('status', 'refund');
    }

    public function test_refund_only_calls_stripe_for_card_portion_of_mixed_payment(): void
    {
        // Card portion = 500 - 200 = 300 → 30000 cents
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('refundPaymentIntent')
            ->once()
            ->with('pi_test_mixed', 'requested_by_customer', 30000)
            ->andReturn(['success' => true, 'refund_id' => 're_mixed_001']);
        $this->app->instance(StripeService::class, $mock);

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 200.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_test_mixed',
        ]);

        $this->postRefund($invoice)->assertOk();
    }

    public function test_refund_does_not_call_stripe_for_credit_only_payment(): void
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('refundPaymentIntent')->never();
        $this->app->instance(StripeService::class, $mock);

        $invoice = $this->makeInvoice([
            'total_amount'   => 500.0,
            'credit_amount'  => 500.0,
            'payment_method' => 'Account Balance',
        ]);

        $this->postRefund($invoice)->assertOk();
    }

    public function test_refund_accepts_payment_intent_id_provided_in_request(): void
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('refundPaymentIntent')
            ->once()
            ->with('pi_provided', 'requested_by_customer', 50000)
            ->andReturn(['success' => true, 'refund_id' => 're_provided_001']);
        $this->app->instance(StripeService::class, $mock);

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 0.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => null,
        ]);

        $this->postRefund($invoice, ['payment_intent_id' => 'pi_provided'])->assertOk();

        $invoice->refresh();
        $this->assertEquals('pi_provided', $invoice->payment_intent_id);
    }

    // ─── Stripe failure rollback ───────────────────────────────────────────────

    public function test_refund_rolls_back_credit_restoration_when_stripe_fails(): void
    {
        $this->client->update(['credit_balance' => 0]);
        $this->mockStripeFailure();

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 200.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_test_fail',
        ]);

        $this->postRefund($invoice)->assertStatus(422);

        // Credits must be rolled back; invoice status must remain 'paid'
        $this->assertEquals(0.0, (float) $this->client->fresh()->credit_balance);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
    }

    public function test_refund_leaves_invoice_unchanged_when_stripe_fails(): void
    {
        $this->mockStripeFailure();

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 0.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_test_fail',
        ]);

        $this->postRefund($invoice)->assertStatus(422);

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertNull($invoice->refund_amount);
    }

    // ─── Transactions ─────────────────────────────────────────────────────────

    public function test_refund_creates_credit_refund_transaction_for_credit_payment(): void
    {
        $invoice = $this->makeInvoice([
            'total_amount'   => 500.0,
            'credit_amount'  => 500.0,
            'payment_method' => 'Account Balance',
        ]);

        $this->postRefund($invoice)->assertOk();

        $this->assertDatabaseHas('transactions', [
            'user_id'        => $this->client->id,
            'type'           => 'refund',
            'payment_method' => 'account_credits',
            'amount'         => 500.0,
            'status'         => 'success',
            'invoice_id'     => (string) $invoice->id,
        ]);
    }

    public function test_refund_creates_card_refund_transaction_for_stripe_payment(): void
    {
        $this->mockStripeSuccess('re_card_tx_001');

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 0.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_card_tx',
        ]);

        $this->postRefund($invoice)->assertOk();

        $this->assertDatabaseHas('transactions', [
            'user_id'           => $this->client->id,
            'type'              => 'refund',
            'payment_method'    => 'credit_card',
            'amount'            => 500.0,
            'status'            => 'success',
            'payment_intent_id' => 'pi_card_tx',
            'invoice_id'        => (string) $invoice->id,
        ]);
    }

    public function test_refund_creates_both_transactions_for_mixed_payment(): void
    {
        $this->mockStripeSuccess();

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 150.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_mixed',
        ]);

        $this->postRefund($invoice)->assertOk();

        $this->assertDatabaseHas('transactions', [
            'invoice_id'     => (string) $invoice->id,
            'payment_method' => 'account_credits',
            'amount'         => 150.0,
        ]);

        $this->assertDatabaseHas('transactions', [
            'invoice_id'     => (string) $invoice->id,
            'payment_method' => 'credit_card',
            'amount'         => 350.0,
        ]);
    }

    public function test_refund_creates_manual_card_transaction_when_no_stripe_pi(): void
    {
        $invoice = $this->makeInvoice([
            'total_amount'      => 200.0,
            'credit_amount'     => 0.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => null,
        ]);

        $this->postRefund($invoice)->assertOk();

        $this->assertDatabaseHas('transactions', [
            'invoice_id'     => (string) $invoice->id,
            'type'           => 'refund',
            'payment_method' => 'credit_card',
            'amount'         => 200.0,
        ]);
    }

    // ─── Invoice history ───────────────────────────────────────────────────────

    public function test_refund_creates_an_invoice_refunded_history_entry(): void
    {
        $invoice = $this->makeInvoice(['payment_method' => 'Account Balance', 'credit_amount' => 500.0]);

        $this->postRefund($invoice)->assertOk();

        $this->assertDatabaseHas('invoice_history', [
            'invoice_id' => $invoice->id,
            'event'      => 'invoice refunded',
        ]);
    }

    public function test_refund_history_entry_describes_credit_portion(): void
    {
        $invoice = $this->makeInvoice([
            'total_amount'   => 300.0,
            'credit_amount'  => 300.0,
            'payment_method' => 'Account Balance',
        ]);

        $this->postRefund($invoice)->assertOk();

        $entry = InvoiceHistory::where('invoice_id', $invoice->id)
            ->where('event', 'invoice refunded')
            ->first();

        $this->assertStringContainsString('returned to client account balance', $entry->description);
    }

    // ─── Response structure ───────────────────────────────────────────────────

    public function test_refund_response_includes_all_expected_fields(): void
    {
        $invoice = $this->makeInvoice(['payment_method' => 'Account Balance', 'credit_amount' => 500.0]);

        $this->postRefund($invoice)
            ->assertOk()
            ->assertJsonStructure([
                'id', 'status', 'refund_amount', 'refunded_at',
                'total_amount', 'invoice_number', 'user',
            ]);
    }

    public function test_refund_response_status_is_refund(): void
    {
        $invoice = $this->makeInvoice(['payment_method' => 'Account Balance', 'credit_amount' => 500.0]);

        $this->postRefund($invoice)
            ->assertOk()
            ->assertJsonPath('status', 'refund');
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $invoice = $this->makeInvoice();

        $this->postJson("/api/admin/invoices/{$invoice->id}/refund")
            ->assertStatus(401);
    }

    public function test_client_cannot_call_refund_endpoint(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/refund")
            ->assertStatus(403);
    }
}
