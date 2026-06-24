<?php

namespace Tests\Feature\Payment;

use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTransactionTest extends TestCase
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

    private function createTransaction(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id'        => $this->client->id,
            'type'           => 'purchase',
            'status'         => 'success',
            'amount'         => 500.0,
            'payment_method' => 'credit_card',
            'description'    => 'Test transaction',
        ], $overrides));
    }

    // ─── List ────────────────────────────────────────────────────────────────

    public function test_admin_can_list_transactions(): void
    {
        $this->createTransaction(['amount' => 100.0]);
        $this->createTransaction(['amount' => 200.0]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/transactions');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'current_page', 'last_page']);

        $this->assertGreaterThanOrEqual(2, $response->json('total'));
    }

    public function test_transaction_list_includes_user_details(): void
    {
        $this->createTransaction();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/transactions');

        $response->assertStatus(200);
        $first = $response->json('data.0');
        $this->assertArrayHasKey('user', $first);
        $this->assertEquals($this->client->email, $first['user']['email']);
    }

    public function test_transaction_list_filters_by_status(): void
    {
        $this->createTransaction(['status' => 'success']);
        $this->createTransaction(['status' => 'failed']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/transactions?status=failed');

        $response->assertStatus(200);
        foreach ($response->json('data') as $tx) {
            $this->assertEquals('failed', $tx['status']);
        }
    }

    public function test_transaction_list_filters_by_type(): void
    {
        $this->createTransaction(['type' => 'purchase']);
        $this->createTransaction(['type' => 'credit_payment']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/transactions?type=credit_payment');

        $response->assertStatus(200);
        foreach ($response->json('data') as $tx) {
            $this->assertEquals('credit_payment', $tx['type']);
        }
    }

    public function test_transaction_list_filters_by_payment_method(): void
    {
        $this->createTransaction(['payment_method' => 'credit_card']);
        $this->createTransaction(['payment_method' => 'account_credits']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/transactions?payment_method=account_credits');

        $response->assertStatus(200);
        foreach ($response->json('data') as $tx) {
            $this->assertEquals('account_credits', $tx['payment_method']);
        }
    }

    public function test_transaction_list_filters_by_date_range(): void
    {
        // created_at is not in $fillable, so we save first and then forceFill the date.
        $old = Transaction::create([
            'user_id'        => $this->client->id,
            'type'           => 'purchase',
            'status'         => 'success',
            'amount'         => 100.0,
            'payment_method' => 'credit_card',
        ]);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        Transaction::create([
            'user_id'        => $this->client->id,
            'type'           => 'purchase',
            'status'         => 'success',
            'amount'         => 200.0,
            'payment_method' => 'credit_card',
        ]);

        $date_from = now()->subDays(5)->toDateString();
        $date_to   = now()->toDateString();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/transactions?date_from={$date_from}&date_to={$date_to}");

        $response->assertStatus(200);
        $amounts = collect($response->json('data'))->pluck('amount')->map(fn ($a) => (float) $a)->toArray();
        $this->assertContains(200.0, $amounts);
        $this->assertNotContains(100.0, $amounts);
    }

    public function test_transaction_list_searches_by_description(): void
    {
        $this->createTransaction(['description' => 'Payment for unique-order-XYZ']);
        $this->createTransaction(['description' => 'Other payment']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/transactions?search=unique-order-XYZ');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
        $this->assertStringContainsString('unique-order-XYZ', $response->json('data.0.description'));
    }

    public function test_transaction_list_respects_per_page_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->createTransaction(['amount' => $i * 10.0]);
        }

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/transactions?per_page=3');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    // ─── Show ────────────────────────────────────────────────────────────────

    public function test_admin_can_retrieve_single_transaction(): void
    {
        $tx = $this->createTransaction(['amount' => 999.0]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/transactions/{$tx->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $tx->id)
            ->assertJsonPath('data.status', 'success');

        $this->assertEquals(999.0, (float) $response->json('data.amount'));
    }

    public function test_fetching_nonexistent_transaction_returns_404(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/transactions/999999')
            ->assertStatus(404);
    }

    public function test_transaction_response_includes_all_required_fields(): void
    {
        $tx = $this->createTransaction([
            'payment_intent_id' => 'pi_test_123',
            'session_id'        => 'sess_abc',
            'session_title'     => 'Test Session',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/transactions/{$tx->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'id',
                'type',
                'status',
                'amount',
                'payment_method',
                'payment_intent_id',
                'session_id',
                'session_title',
                'description',
                'created_at',
                'user',
            ]]);
    }

    // ─── Access control ──────────────────────────────────────────────────────

    public function test_client_cannot_access_admin_transaction_list(): void
    {
        $this->actingAs($this->client, 'api')
            ->getJson('/api/admin/transactions')
            ->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/transactions')
            ->assertStatus(401);
    }
}
