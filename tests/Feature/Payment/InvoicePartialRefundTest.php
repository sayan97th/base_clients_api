<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Covers the partial-refund flow and the Stripe-aligned invoice statuses
 * ('partial_refund', 'dispute') added alongside the existing refund handling.
 */
class InvoicePartialRefundTest extends TestCase
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

    /**
     * Stub StripeService so card-portion refunds don't hit the network.
     */
    private function mockStripeRefund(bool $success = true): Mockery\MockInterface
    {
        $mock = Mockery::mock(StripeService::class);

        $mock->shouldReceive('refundPaymentIntent')
            ->andReturn($success
                ? ['success' => true, 'refund_id' => 're_test_123']
                : ['success' => false, 'message' => 'Stripe refund failed.']);

        $this->app->instance(StripeService::class, $mock);

        return $mock;
    }

    // ─── Status transitions ────────────────────────────────────────────────────

    public function test_partial_refund_sets_status_to_partial_refund(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 500.0]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", [
                'refund_amount' => 200.0,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'partial_refund');

        $this->assertDatabaseHas('invoices', [
            'id'            => $invoice->id,
            'status'        => 'partial_refund',
            'refund_amount' => 200.0,
        ]);
    }

    public function test_partial_refund_for_full_amount_escalates_to_refund(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 500.0]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", [
                'refund_amount' => 500.0,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'refund');

        $this->assertDatabaseHas('invoices', [
            'id'            => $invoice->id,
            'status'        => 'refund',
            'refund_amount' => 500.0,
        ]);
    }

    public function test_successive_partial_refunds_accumulate_and_escalate_to_refund(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 500.0]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", ['refund_amount' => 200.0])
            ->assertStatus(200)
            ->assertJsonPath('status', 'partial_refund');

        // A second partial refund is allowed from the 'partial_refund' status and,
        // once the total is reached, the invoice escalates to a full 'refund'.
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", ['refund_amount' => 300.0])
            ->assertStatus(200)
            ->assertJsonPath('status', 'refund');

        $this->assertDatabaseHas('invoices', [
            'id'            => $invoice->id,
            'refund_amount' => 500.0,
            'status'        => 'refund',
        ]);
    }

    // ─── Validation guards ───────────────────────────────────────────────────────

    public function test_partial_refund_rejects_amount_exceeding_balance(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 500.0]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", [
                'refund_amount' => 600.0,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('invoices', [
            'id'     => $invoice->id,
            'status' => 'paid',
        ]);
    }

    public function test_partial_refund_rejects_zero_or_negative_amount(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 500.0]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", [
                'refund_amount' => 0,
            ])
            ->assertStatus(422);
    }

    public function test_partial_refund_requires_a_paid_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => 'unpaid', 'date_paid' => null]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", [
                'refund_amount' => 100.0,
            ])
            ->assertStatus(422);
    }

    public function test_partial_refund_on_missing_invoice_returns_404(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/invoices/00000000-0000-0000-0000-000000000000/partial-refund', [
                'refund_amount' => 100.0,
            ])
            ->assertStatus(404);
    }

    // ─── Side effects: transactions, credits, Stripe, history ──────────────────────

    public function test_partial_refund_records_a_partial_refund_transaction(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 500.0]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", ['refund_amount' => 150.0])
            ->assertStatus(200);

        $this->assertDatabaseHas('transactions', [
            'user_id'    => $this->client->id,
            'type'       => 'partial_refund',
            'status'     => 'success',
            'amount'     => 150.0,
            'invoice_id' => (string) $invoice->id,
        ]);
    }

    public function test_partial_refund_restores_account_credits_for_credit_paid_invoice(): void
    {
        // Invoice fully paid with account credits — the refund returns credits to the balance.
        $invoice = $this->makeInvoice([
            'total_amount'   => 500.0,
            'credit_amount'  => 500.0,
            'payment_method' => 'Account Balance',
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", ['refund_amount' => 200.0])
            ->assertStatus(200)
            ->assertJsonPath('status', 'partial_refund');

        $this->assertEquals(200.0, (float) $this->client->fresh()->credit_balance);

        $this->assertDatabaseHas('transactions', [
            'invoice_id'     => (string) $invoice->id,
            'type'           => 'partial_refund',
            'payment_method' => 'account_credits',
            'amount'         => 200.0,
        ]);
    }

    public function test_partial_refund_issues_stripe_refund_for_card_portion(): void
    {
        // Expect exactly one Stripe refund for the card portion, expressed in cents.
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('refundPaymentIntent')
            ->once()
            ->with('pi_test_abc', 'requested_by_customer', 20000)
            ->andReturn(['success' => true, 'refund_id' => 're_test_123']);
        $this->app->instance(StripeService::class, $mock);

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 0.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_test_abc',
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", ['refund_amount' => 200.0])
            ->assertStatus(200)
            ->assertJsonPath('status', 'partial_refund');

        $this->assertDatabaseHas('transactions', [
            'invoice_id'        => (string) $invoice->id,
            'type'              => 'partial_refund',
            'payment_method'    => 'credit_card',
            'payment_intent_id' => 'pi_test_abc',
            'amount'            => 200.0,
        ]);
    }

    public function test_partial_refund_rolls_back_when_stripe_fails(): void
    {
        $this->mockStripeRefund(success: false);

        $invoice = $this->makeInvoice([
            'total_amount'      => 500.0,
            'credit_amount'     => 0.0,
            'payment_method'    => 'Credit Card',
            'payment_intent_id' => 'pi_test_fail',
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", ['refund_amount' => 200.0])
            ->assertStatus(422);

        // Status is untouched and no refund amount is recorded when Stripe fails.
        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertEmpty($invoice->refund_amount);
    }

    public function test_partial_refund_records_a_history_entry(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 500.0]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/partial-refund", ['refund_amount' => 100.0])
            ->assertStatus(200);

        $this->assertDatabaseHas('invoice_history', [
            'invoice_id' => $invoice->id,
            'event'      => 'partial refund issued',
        ]);
    }

    // ─── List filtering by the new statuses ────────────────────────────────────────

    public function test_invoice_list_can_be_filtered_by_partial_refund_status(): void
    {
        $this->makeInvoice(['status' => 'partial_refund', 'refund_amount' => 100.0]);
        $this->makeInvoice(['status' => 'paid']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/invoices?status=partial_refund');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
        foreach ($response->json('data') as $invoice) {
            $this->assertEquals('partial_refund', $invoice['status']);
        }
    }

    public function test_invoice_list_accepts_dispute_status_filter(): void
    {
        $this->makeInvoice(['status' => 'dispute']);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/invoices?status=dispute')
            ->assertStatus(200);
    }

    public function test_invoice_list_rejects_unknown_status_filter(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/invoices?status=bogus_status')
            ->assertStatus(422);
    }
}
