<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicInvoiceViewTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private string $share_key = 'test-share-key-abc123';

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Regular client']);

        $this->client = User::factory()->create(['is_active' => true]);
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

    // ─── Token is required ───────────────────────────────────────────────────

    public function test_view_requires_token_query_parameter(): void
    {
        $invoice = $this->makeInvoice();

        $this->getJson("/api/invoices/{$invoice->unique_id}/view")
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token is required.');
    }

    // ─── Valid public view ───────────────────────────────────────────────────

    public function test_valid_token_returns_invoice_detail(): void
    {
        $invoice = $this->makeInvoice();

        $response = $this->getJson("/api/invoices/{$invoice->unique_id}/view?token={$this->share_key}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'invoice_number',
                    'unique_id',
                    'status',
                    'total',
                    'subtotal',
                    'line_items',
                ],
            ]);
    }

    public function test_invoice_unique_id_is_returned_in_data(): void
    {
        $invoice = $this->makeInvoice();

        $response = $this->getJson("/api/invoices/{$invoice->unique_id}/view?token={$this->share_key}");

        $response->assertStatus(200)
            ->assertJsonPath('data.unique_id', $invoice->unique_id);
    }

    // ─── Token mismatch ──────────────────────────────────────────────────────

    public function test_wrong_token_returns_403(): void
    {
        $invoice = $this->makeInvoice();

        $this->getJson("/api/invoices/{$invoice->unique_id}/view?token=wrong-token")
            ->assertStatus(403);
    }

    // ─── Sharing disabled ────────────────────────────────────────────────────

    public function test_disabled_sharing_returns_403(): void
    {
        $invoice = $this->makeInvoice(['sharing_enabled' => false]);

        $this->getJson("/api/invoices/{$invoice->unique_id}/view?token={$this->share_key}")
            ->assertStatus(403);
    }

    // ─── Not found ───────────────────────────────────────────────────────────

    public function test_nonexistent_invoice_returns_404(): void
    {
        $this->getJson("/api/invoices/NONEXISTENT/view?token={$this->share_key}")
            ->assertStatus(404);
    }

    // ─── Response structure ──────────────────────────────────────────────────

    public function test_response_includes_formatted_total(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 750.0]);

        $response = $this->getJson("/api/invoices/{$invoice->unique_id}/view?token={$this->share_key}");

        $response->assertStatus(200);
        $this->assertStringContainsString('750', $response->json('data.total'));
    }

    public function test_response_includes_invoice_status(): void
    {
        $invoice = $this->makeInvoice(['status' => 'overdue']);

        $response = $this->getJson("/api/invoices/{$invoice->unique_id}/view?token={$this->share_key}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'overdue');
    }

    public function test_paid_invoice_is_accessible_via_public_view(): void
    {
        $invoice = $this->makeInvoice(['status' => 'paid', 'date_paid' => now()]);

        $response = $this->getJson("/api/invoices/{$invoice->unique_id}/view?token={$this->share_key}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'paid');
    }
}
