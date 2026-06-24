<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceShareLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Regular client']);

        $this->admin  = User::factory()->create(['is_active' => true]);
        $this->client = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
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
            'sharing_enabled'  => false,
            'share_key'        => null,
            'date_issued'      => now(),
            'date_due'         => now()->addDays(30),
        ], $overrides));
    }

    // ─── GET share links ──────────────────────────────────────────────────────

    public function test_admin_can_retrieve_share_link_data(): void
    {
        $invoice = $this->makeInvoice();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice->id}/share-links");

        $response->assertStatus(200)
            ->assertJsonStructure(['sharing_enabled', 'private_link', 'public_link']);
    }

    public function test_share_link_auto_generates_share_key_when_none_exists(): void
    {
        $invoice = $this->makeInvoice(['share_key' => null]);

        $this->assertNull($invoice->share_key);

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice->id}/share-links")
            ->assertStatus(200);

        $this->assertNotNull($invoice->fresh()->share_key);
    }

    public function test_share_link_uses_existing_share_key_when_present(): void
    {
        $existing_key = 'existing-share-key-48chars-xxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $invoice      = $this->makeInvoice(['share_key' => $existing_key]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice->id}/share-links");

        $response->assertStatus(200);
        $this->assertStringContainsString($existing_key, $response->json('public_link'));
    }

    public function test_private_link_contains_invoice_unique_id(): void
    {
        $invoice = $this->makeInvoice();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice->id}/share-links");

        $response->assertStatus(200);
        $this->assertStringContainsString($invoice->unique_id, $response->json('private_link'));
    }

    public function test_public_link_contains_token_query_param(): void
    {
        $invoice = $this->makeInvoice();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice->id}/share-links");

        $response->assertStatus(200);
        $this->assertStringContainsString('token=', $response->json('public_link'));
    }

    public function test_retrieving_share_links_for_nonexistent_invoice_returns_404(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/invoices/00000000-0000-0000-0000-000000000000/share-links')
            ->assertStatus(404);
    }

    public function test_client_cannot_retrieve_share_links(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->getJson("/api/admin/invoices/{$invoice->id}/share-links")
            ->assertStatus(403);
    }

    // ─── PATCH share links ────────────────────────────────────────────────────

    public function test_admin_can_enable_sharing(): void
    {
        $invoice = $this->makeInvoice(['sharing_enabled' => false]);

        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}/share-links", [
                'sharing_enabled' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('sharing_enabled', true);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'sharing_enabled' => true]);
    }

    public function test_admin_can_disable_sharing(): void
    {
        $invoice = $this->makeInvoice(['sharing_enabled' => true, 'share_key' => 'some-key-here']);

        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}/share-links", [
                'sharing_enabled' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('sharing_enabled', false);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'sharing_enabled' => false]);
    }

    public function test_enabling_sharing_generates_share_key_if_missing(): void
    {
        $invoice = $this->makeInvoice(['sharing_enabled' => false, 'share_key' => null]);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}/share-links", [
                'sharing_enabled' => true,
            ])->assertStatus(200);

        $this->assertNotNull($invoice->fresh()->share_key);
    }

    public function test_updating_share_links_for_nonexistent_invoice_returns_404(): void
    {
        $this->actingAs($this->admin, 'api')
            ->patchJson('/api/admin/invoices/00000000-0000-0000-0000-000000000000/share-links', [
                'sharing_enabled' => true,
            ])->assertStatus(404);
    }

    public function test_client_cannot_update_share_links(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}/share-links", [
                'sharing_enabled' => true,
            ])->assertStatus(403);
    }

    public function test_update_response_includes_both_link_types(): void
    {
        $invoice = $this->makeInvoice();

        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/invoices/{$invoice->id}/share-links", [
                'sharing_enabled' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['sharing_enabled', 'private_link', 'public_link']);
    }

    // ─── Share key uniqueness ─────────────────────────────────────────────────

    public function test_two_invoices_get_different_share_keys(): void
    {
        $invoice_a = $this->makeInvoice();
        $invoice_b = $this->makeInvoice();

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice_a->id}/share-links")
            ->assertStatus(200);

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice_b->id}/share-links")
            ->assertStatus(200);

        $this->assertNotEquals(
            $invoice_a->fresh()->share_key,
            $invoice_b->fresh()->share_key
        );
    }

    // ─── Public access gating ─────────────────────────────────────────────────

    public function test_enabling_sharing_allows_public_invoice_view(): void
    {
        $invoice = $this->makeInvoice(['sharing_enabled' => true, 'share_key' => 'test-key-for-view-123']);

        $this->getJson("/api/invoices/{$invoice->unique_id}/view?token=test-key-for-view-123")
            ->assertStatus(200);
    }

    public function test_disabling_sharing_blocks_public_invoice_view(): void
    {
        $invoice = $this->makeInvoice(['sharing_enabled' => false, 'share_key' => 'test-key-for-view-123']);

        $this->getJson("/api/invoices/{$invoice->unique_id}/view?token=test-key-for-view-123")
            ->assertStatus(403);
    }

    // ─── Admin notification email settings context ────────────────────────────

    public function test_share_link_response_does_not_expose_sensitive_invoice_data(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 999.99]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice->id}/share-links");

        $response->assertStatus(200);

        // Response should only contain link data, not financial data
        $this->assertArrayNotHasKey('total_amount', $response->json());
        $this->assertArrayNotHasKey('user_id', $response->json());
    }
}
