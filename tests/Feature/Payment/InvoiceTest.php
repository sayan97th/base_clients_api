<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
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

    private function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'unique_id'       => strtoupper(bin2hex(random_bytes(4))),
            'invoice_number'  => 'BSM-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id'         => $this->client->id,
            'status'          => 'unpaid',
            'payment_method'  => 'Account Balance',
            'currency_type'   => 'usd',
            'subtotal_amount' => 500.0,
            'discount_amount' => 0.0,
            'total_amount'    => 500.0,
            'credit_amount'   => 0.0,
            'date_issued'     => now(),
        ], $overrides));
    }

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->client->id,
            'line_items' => [
                [
                    'item_name'        => 'Link Building Package',
                    'description'      => '10 links',
                    'price'            => 250.0,
                    'quantity'         => 2,
                    'discount_percent' => 0,
                ],
            ],
            'currency_type'              => 'usd',
            'date_due'                   => now()->addDays(30)->toDateString(),
            'notes'                      => null,
            'send_client_notification'   => false,
            'send_admin_notification'    => false,
        ], $overrides);
    }

    // ─── List ────────────────────────────────────────────────────────────────

    public function test_admin_can_list_invoices(): void
    {
        $this->makeInvoice();
        $this->makeInvoice();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/invoices');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'current_page', 'last_page']);

        $this->assertGreaterThanOrEqual(2, $response->json('total'));
    }

    public function test_invoice_list_can_be_filtered_by_status(): void
    {
        $this->makeInvoice(['status' => 'paid']);
        $this->makeInvoice(['status' => 'unpaid']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/invoices?status=paid');

        $response->assertStatus(200);
        foreach ($response->json('data') as $invoice) {
            $this->assertEquals('paid', $invoice['status']);
        }
    }

    // ─── Create ──────────────────────────────────────────────────────────────

    public function test_admin_can_create_invoice_with_line_items(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/invoices', $this->storePayload());

        $response->assertStatus(201)
            ->assertJsonPath('status', 'unpaid');

        $this->assertEquals(500.0, (float) $response->json('total_amount'));

        $this->assertDatabaseHas('invoices', [
            'user_id'      => $this->client->id,
            'total_amount' => 500.0,
            'status'       => 'unpaid',
        ]);
    }

    public function test_invoice_creation_computes_discount_from_line_items(): void
    {
        $payload = $this->storePayload([
            'line_items' => [
                [
                    'item_name'        => 'Discounted Package',
                    'price'            => 1000.0,
                    'quantity'         => 1,
                    'discount_percent' => 20, // 200 off
                ],
            ],
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/invoices', $payload);

        $response->assertStatus(201);
        $this->assertEquals(800.0, (float) $response->json('total_amount'));
        $this->assertEquals(200.0, (float) $response->json('discount_amount'));
    }

    public function test_creating_invoice_for_nonexistent_user_returns_404(): void
    {
        $payload          = $this->storePayload();
        $payload['user_id'] = 99999;

        // The FormRequest validates user_id with exists:users,id and returns 422
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/invoices', $payload)
            ->assertStatus(422);
    }

    // ─── Show ────────────────────────────────────────────────────────────────

    public function test_admin_can_retrieve_single_invoice(): void
    {
        $invoice = $this->makeInvoice();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $invoice->id)
            ->assertJsonPath('status', 'unpaid');
    }

    public function test_fetching_nonexistent_invoice_returns_404(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/invoices/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    // ─── Mark Paid ───────────────────────────────────────────────────────────

    public function test_admin_can_mark_invoice_as_paid(): void
    {
        $invoice = $this->makeInvoice(['status' => 'unpaid']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/mark-paid");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'paid');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
    }

    public function test_marking_already_paid_invoice_returns_422(): void
    {
        $invoice = $this->makeInvoice(['status' => 'paid', 'date_paid' => now()]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/mark-paid")
            ->assertStatus(422);
    }

    public function test_marking_invoice_paid_creates_transaction_record(): void
    {
        $invoice = $this->makeInvoice(['status' => 'unpaid', 'total_amount' => 750.0]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/mark-paid");

        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->client->id,
            'status'  => 'success',
            'amount'  => 750.0,
        ]);
    }

    // ─── Mark Unpaid ─────────────────────────────────────────────────────────

    public function test_admin_can_mark_invoice_as_unpaid(): void
    {
        $invoice = $this->makeInvoice(['status' => 'paid', 'date_paid' => now()]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/mark-unpaid");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'unpaid');
    }

    public function test_marking_already_unpaid_invoice_returns_422(): void
    {
        $invoice = $this->makeInvoice(['status' => 'unpaid']);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/mark-unpaid")
            ->assertStatus(422);
    }

    // ─── Refund ──────────────────────────────────────────────────────────────

    public function test_admin_can_mark_invoice_as_refunded(): void
    {
        $invoice = $this->makeInvoice(['status' => 'paid', 'date_paid' => now()]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/refund");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'refund');
    }

    public function test_refunding_already_refunded_invoice_returns_422(): void
    {
        $invoice = $this->makeInvoice(['status' => 'refund']);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/refund")
            ->assertStatus(422);
    }

    // ─── Void ────────────────────────────────────────────────────────────────

    public function test_admin_can_void_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => 'unpaid']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/void");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'void');
    }

    public function test_voiding_already_voided_invoice_returns_422(): void
    {
        $invoice = $this->makeInvoice(['status' => 'void']);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/void")
            ->assertStatus(422);
    }

    // ─── Duplicate ───────────────────────────────────────────────────────────

    public function test_admin_can_duplicate_invoice(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 300.0]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/duplicate");

        $response->assertStatus(201)
            ->assertJsonPath('status', 'unpaid');

        $this->assertEquals(300.0, (float) $response->json('total_amount'));
        $this->assertNotEquals($invoice->id, $response->json('id'));
    }

    // ─── Invoice number regression (duplicate BSM-XXXX bug) ─────────────────
    //
    // Invoice numbers used to be computed as `Invoice::count() + 1`. Once an
    // invoice was deleted, the count dropped below the highest number ever
    // issued, so the next create() could reuse a number that was still on
    // file and fail with "Integrity constraint violation: 1062 Duplicate
    // entry 'BSM-XXXX' for key invoices_invoice_number_unique".

    public function test_creating_invoices_through_the_api_produces_unique_sequential_numbers(): void
    {
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $response = $this->actingAs($this->admin, 'api')
                ->postJson('/api/admin/invoices', $this->storePayload());

            $response->assertStatus(201);
            $numbers[] = $response->json('invoice_number');
        }

        $this->assertEquals(['BSM-0001', 'BSM-0002', 'BSM-0003'], $numbers);
    }

    /**
     * This is the exact production failure: five invoices are created
     * (BSM-0001..BSM-0005), the admin deletes one stray early invoice
     * (BSM-0001) and keeps the rest. Invoice::count() drops to 4, so the old
     * `count() + 1` formula would reissue BSM-0005 — which is still on file
     * — and the insert would fail with the reported 1062 duplicate-entry
     * error instead of returning 201.
     */
    public function test_creating_invoice_after_an_early_invoice_is_deleted_does_not_collide(): void
    {
        $created_ids     = [];
        $created_numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($this->admin, 'api')
                ->postJson('/api/admin/invoices', $this->storePayload());

            $response->assertStatus(201);
            $created_ids[]     = $response->json('id');
            $created_numbers[] = $response->json('invoice_number');
        }

        $this->assertEquals(
            ['BSM-0001', 'BSM-0002', 'BSM-0003', 'BSM-0004', 'BSM-0005'],
            $created_numbers
        );

        $this->actingAs($this->admin, 'api')
            ->deleteJson('/api/admin/invoices/' . $created_ids[0])
            ->assertNoContent();

        $this->assertEquals(4, Invoice::count());

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/invoices', $this->storePayload());

        $response->assertStatus(201);

        $new_number = $response->json('invoice_number');
        $this->assertEquals('BSM-0006', $new_number);
        $this->assertDatabaseHas('invoices', ['invoice_number' => $new_number]);

        $all_numbers = Invoice::pluck('invoice_number');
        $this->assertEquals(
            $all_numbers->count(),
            $all_numbers->unique()->count(),
            'Invoice numbers must be unique after creating post-deletion.'
        );
    }

    public function test_duplicating_invoice_after_earlier_invoices_were_deleted_does_not_collide(): void
    {
        $created_ids = [];
        for ($i = 0; $i < 4; $i++) {
            $response = $this->actingAs($this->admin, 'api')
                ->postJson('/api/admin/invoices', $this->storePayload());

            $created_ids[] = $response->json('id');
        }

        $surviving_invoice_id = end($created_ids);

        foreach (array_slice($created_ids, 0, 3) as $id) {
            $this->actingAs($this->admin, 'api')
                ->deleteJson("/api/admin/invoices/{$id}")
                ->assertNoContent();
        }

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$surviving_invoice_id}/duplicate");

        $response->assertStatus(201);

        $numbers = Invoice::pluck('invoice_number');
        $this->assertEquals(
            $numbers->count(),
            $numbers->unique()->count(),
            'Invoice numbers must be unique after duplicating post-deletion.'
        );
    }

    // ─── Delete ──────────────────────────────────────────────────────────────

    public function test_admin_can_delete_invoice(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/admin/invoices/{$invoice->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    // ─── Invoice history ─────────────────────────────────────────────────────

    public function test_invoice_history_is_recorded_on_creation(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/invoices', $this->storePayload());

        $invoice_id = $response->json('id');

        $history = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice_id}/history");

        $history->assertStatus(200);
        $this->assertNotEmpty($history->json());
        $this->assertEquals('invoice created', $history->json('0.event'));
    }

    // ─── Access control ──────────────────────────────────────────────────────

    public function test_client_cannot_access_admin_invoice_list(): void
    {
        $this->actingAs($this->client, 'api')
            ->getJson('/api/admin/invoices')
            ->assertStatus(403);
    }
}
