<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the admin invoice edit flow (PATCH /api/admin/invoices/{id}).
 *
 * The regression these tests guard against: the frontend loads `date_due`
 * as an ISO 8601 string and sends it back on save. The validator only
 * accepts `Y-m-d`, so the whole update transaction (line items, discounts,
 * total) used to fail silently. `prepareForValidation()` now normalizes the
 * date, and these tests lock that behavior in alongside the recomputation
 * of subtotal / discount / total.
 */
class InvoiceUpdateTest extends TestCase
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
        $this->client = User::factory()->create(['is_active' => true]);

        $this->admin->assignRole('admin');
        $this->client->assignRole('client');
    }

    /**
     * Creates a persisted invoice with a single line item to edit.
     */
    private function makeInvoiceWithItem(array $overrides = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'unique_id'       => strtoupper(bin2hex(random_bytes(4))),
            'invoice_number'  => 'BSM-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id'         => $this->client->id,
            'status'          => 'unpaid',
            'payment_method'  => 'Account Balance',
            'currency_type'   => 'usd',
            'subtotal_amount' => 500.0,
            'discount_amount' => 0.0,
            'discount_type'   => null,
            'total_amount'    => 500.0,
            'credit_amount'   => 0.0,
            'date_issued'     => now(),
            'date_due'        => now()->addDays(30),
        ], $overrides));

        $invoice->lineItems()->create([
            'item_name'        => 'Original Package',
            'description'      => 'Original line item',
            'price'            => 500.0,
            'quantity'         => 1,
            'discount_percent' => 0,
            'item_total'       => 500.0,
        ]);

        return $invoice;
    }

    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'user_id'   => $this->client->id,
            'date_due'  => now()->addDays(15)->toDateString(),
            'line_items' => [
                [
                    'item_name'        => 'Updated Package',
                    'description'      => '10 links',
                    'price'            => 1000.0,
                    'quantity'         => 1,
                    'discount_percent' => 0,
                ],
            ],
            'notes'                    => null,
            'send_update_notification' => false,
        ], $overrides);
    }

    // ─── Date format normalization (the bug) ───────────────────────────────────

    public function test_admin_can_update_invoice_with_iso8601_due_date(): void
    {
        $invoice = $this->makeInvoiceWithItem();

        // Exactly what the frontend used to send back from a loaded invoice.
        $payload = $this->updatePayload(['date_due' => '2026-07-24T00:00:00+00:00']);

        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", $payload);

        $response->assertStatus(200);
        $this->assertEquals('2026-07-24', $invoice->fresh()->date_due->format('Y-m-d'));
    }

    public function test_admin_can_update_invoice_with_ymd_due_date(): void
    {
        $invoice = $this->makeInvoiceWithItem();

        $payload = $this->updatePayload(['date_due' => '2026-08-15']);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", $payload)
            ->assertStatus(200);

        $this->assertEquals('2026-08-15', $invoice->fresh()->date_due->format('Y-m-d'));
    }

    public function test_updating_invoice_with_invalid_due_date_returns_422(): void
    {
        $invoice = $this->makeInvoiceWithItem();

        $payload = $this->updatePayload(['date_due' => 'not-a-real-date']);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_due');
    }

    // ─── Total / discount recomputation ────────────────────────────────────────

    public function test_updating_line_items_recomputes_total(): void
    {
        $invoice = $this->makeInvoiceWithItem();

        $payload = $this->updatePayload([
            'line_items' => [
                ['item_name' => 'Item A', 'price' => 1000.0, 'quantity' => 2, 'discount_percent' => 0],
                ['item_name' => 'Item B', 'price' => 250.0,  'quantity' => 1, 'discount_percent' => 0],
            ],
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", $payload);

        $response->assertStatus(200);
        $this->assertEquals(2250.0, (float) $response->json('total_amount'));
        $this->assertEquals(0.0, (float) $response->json('discount_amount'));
    }

    public function test_applying_a_discount_reduces_the_invoice_total(): void
    {
        // The client's scenario: take $50 off a $500 line via a discount.
        $invoice = $this->makeInvoiceWithItem();

        $payload = $this->updatePayload([
            'line_items' => [
                ['item_name' => 'Discounted Package', 'price' => 500.0, 'quantity' => 1, 'discount_percent' => 10],
            ],
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", $payload);

        $response->assertStatus(200);
        $this->assertEquals(450.0, (float) $response->json('total_amount'));
        $this->assertEquals(50.0, (float) $response->json('discount_amount'));
        $this->assertEquals('line_item', $response->json('discount_type'));
    }

    public function test_removing_a_discount_clears_the_discount_type(): void
    {
        $invoice = $this->makeInvoiceWithItem([
            'discount_amount' => 50.0,
            'discount_type'   => 'line_item',
            'subtotal_amount' => 450.0,
            'total_amount'    => 450.0,
        ]);

        $payload = $this->updatePayload([
            'line_items' => [
                ['item_name' => 'Full Price Package', 'price' => 500.0, 'quantity' => 1, 'discount_percent' => 0],
            ],
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", $payload);

        $response->assertStatus(200);
        $this->assertEquals(500.0, (float) $response->json('total_amount'));
        $this->assertEquals(0.0, (float) $response->json('discount_amount'));
        $this->assertNull($response->json('discount_type'));
    }

    public function test_update_replaces_existing_line_items(): void
    {
        $invoice = $this->makeInvoiceWithItem();

        $payload = $this->updatePayload([
            'line_items' => [
                ['item_name' => 'Replacement A', 'price' => 100.0, 'quantity' => 1, 'discount_percent' => 0],
                ['item_name' => 'Replacement B', 'price' => 200.0, 'quantity' => 1, 'discount_percent' => 0],
            ],
        ]);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", $payload)
            ->assertStatus(200);

        $this->assertEquals(2, InvoiceLineItem::where('invoice_id', $invoice->id)->count());
        $this->assertDatabaseMissing('invoice_line_items', [
            'invoice_id' => $invoice->id,
            'item_name'  => 'Original Package',
        ]);
        $this->assertDatabaseHas('invoice_line_items', [
            'invoice_id' => $invoice->id,
            'item_name'  => 'Replacement A',
        ]);
    }

    // ─── Partial updates ────────────────────────────────────────────────────────

    public function test_updating_due_date_only_preserves_line_items(): void
    {
        $invoice = $this->makeInvoiceWithItem();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", [
                'date_due' => '2026-09-01',
            ])
            ->assertStatus(200);

        // Line items must be untouched when not present in the payload.
        $this->assertEquals(1, InvoiceLineItem::where('invoice_id', $invoice->id)->count());
        $this->assertEquals(500.0, (float) $invoice->fresh()->total_amount);
        $this->assertEquals('2026-09-01', $invoice->fresh()->date_due->format('Y-m-d'));
    }

    public function test_admin_can_update_notes(): void
    {
        $invoice = $this->makeInvoiceWithItem();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", [
                'notes' => 'Adjusted per client agreement.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('notes', 'Adjusted per client agreement.');
    }

    // ─── History & side effects ──────────────────────────────────────────────────

    public function test_update_records_an_invoice_history_entry(): void
    {
        $invoice = $this->makeInvoiceWithItem();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", $this->updatePayload())
            ->assertStatus(200);

        $this->assertDatabaseHas('invoice_history', [
            'invoice_id' => $invoice->id,
            'event'      => 'invoice updated',
        ]);
    }

    // ─── Error handling & access control ──────────────────────────────────────────

    public function test_updating_nonexistent_invoice_returns_404(): void
    {
        $this->actingAs($this->admin, 'api')
            ->patchJson('/api/admin/invoices/00000000-0000-0000-0000-000000000000', $this->updatePayload())
            ->assertStatus(404);
    }

    public function test_client_cannot_update_invoice(): void
    {
        $invoice = $this->makeInvoiceWithItem();

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}", $this->updatePayload())
            ->assertStatus(403);
    }
}
