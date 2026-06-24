<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCreditAssignControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->admin  = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 0]);
        $this->client->assignRole('client');
    }

    private function assignPayload(array $overrides = []): array
    {
        return array_merge([
            'user_id'     => $this->client->id,
            'amount'      => 100,
            'type'        => 'credit',
            'description' => 'Test credit assignment',
        ], $overrides);
    }

    // ─── Authentication and authorization ─────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->postJson('/api/admin/credits/assign', $this->assignPayload())
            ->assertStatus(401);
    }

    public function test_client_role_cannot_access_assign_endpoint(): void
    {
        $this->actingAs($this->client, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload())
            ->assertStatus(403);
    }

    public function test_admin_can_assign_credits(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload())
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ─── Credit operation: add ────────────────────────────────────────────────

    public function test_adding_credits_increases_user_balance(): void
    {
        $this->client->update(['credit_balance' => 200]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload([
                'amount' => 300,
                'type'   => 'credit',
            ]))
            ->assertStatus(200)
            ->assertJson(['new_balance' => 500]);

        $this->assertEquals(500, $this->client->fresh()->credit_balance);
    }

    public function test_adding_credits_returns_updated_balance_in_response(): void
    {
        $this->client->update(['credit_balance' => 50]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload([
                'amount' => 100,
                'type'   => 'credit',
            ]))
            ->assertStatus(200);

        $this->assertEquals(150, $response->json('new_balance'));
    }

    public function test_adding_credits_creates_credit_transaction_record(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload([
                'amount'      => 500,
                'type'        => 'credit',
                'description' => 'Promo credits',
            ]))
            ->assertStatus(200);

        $this->assertDatabaseHas('credit_transactions', [
            'user_id'     => $this->client->id,
            'amount'      => 500,
            'type'        => 'credit',
            'description' => 'Promo credits',
        ]);
    }

    public function test_response_includes_transaction_details(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload([
                'amount'      => 200,
                'type'        => 'credit',
                'description' => 'Manual top-up',
            ]))
            ->assertStatus(200);

        $response->assertJsonStructure([
            'success',
            'new_balance',
            'transaction' => [
                'id',
                'user_id',
                'user'        => ['id', 'first_name', 'last_name', 'email'],
                'amount',
                'type',
                'description',
                'created_by',
                'created_at',
            ],
        ]);

        $this->assertEquals('credit', $response->json('transaction.type'));
        $this->assertEquals(200, $response->json('transaction.amount'));
        $this->assertEquals('Manual top-up', $response->json('transaction.description'));
    }

    // ─── Credit operation: deduct ─────────────────────────────────────────────

    public function test_deducting_credits_decreases_user_balance(): void
    {
        $this->client->update(['credit_balance' => 500]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload([
                'amount' => 200,
                'type'   => 'debit',
            ]))
            ->assertStatus(200)
            ->assertJson(['new_balance' => 300]);

        $this->assertEquals(300, $this->client->fresh()->credit_balance);
    }

    public function test_deducting_credits_creates_debit_transaction_record(): void
    {
        $this->client->update(['credit_balance' => 300]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload([
                'amount' => 100,
                'type'   => 'debit',
            ]))
            ->assertStatus(200);

        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $this->client->id,
            'amount'  => 100,
            'type'    => 'debit',
        ]);
    }

    public function test_cannot_deduct_more_credits_than_user_balance(): void
    {
        $this->client->update(['credit_balance' => 50]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload([
                'amount' => 100,
                'type'   => 'debit',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient credit balance.');
    }

    public function test_deducting_exact_balance_succeeds(): void
    {
        $this->client->update(['credit_balance' => 100]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload([
                'amount' => 100,
                'type'   => 'debit',
            ]))
            ->assertStatus(200)
            ->assertJson(['new_balance' => 0]);
    }

    // ─── Validation ────────────────────────────────────────────────────────────

    public function test_missing_user_id_returns_422(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', [
                'amount' => 100,
                'type'   => 'credit',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_missing_amount_returns_422(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', [
                'user_id' => $this->client->id,
                'type'    => 'credit',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_amount_less_than_1_returns_422(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload(['amount' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_invalid_type_returns_422(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload(['type' => 'invalid']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_non_existent_user_id_returns_422(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload(['user_id' => 99999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_description_is_optional(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', [
                'user_id' => $this->client->id,
                'amount'  => 50,
                'type'    => 'credit',
            ])
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ─── Non-client user cannot be targeted ───────────────────────────────────

    public function test_cannot_assign_credits_to_non_client_user(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload([
                'user_id' => $this->admin->id,
            ]))
            ->assertStatus(404);
    }

    // ─── Transaction history endpoint ─────────────────────────────────────────

    public function test_transactions_endpoint_is_accessible_by_admin(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/transactions')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'last_page', 'total']);
    }

    public function test_transactions_endpoint_returns_paginated_records(): void
    {
        // Create transactions by assigning credits
        $this->client->update(['credit_balance' => 1000]);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->admin, 'api')
                ->postJson('/api/admin/credits/assign', $this->assignPayload(['amount' => 10]));
        }

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/transactions')
            ->assertStatus(200);

        $this->assertEquals(5, $response->json('total'));
    }

    public function test_transactions_endpoint_filters_by_type(): void
    {
        $this->client->update(['credit_balance' => 500]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload(['type' => 'credit', 'amount' => 100]));

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload(['type' => 'debit', 'amount' => 50]));

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/transactions?type=credit')
            ->assertStatus(200);

        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('credit', $response->json('data.0.type'));
    }

    public function test_transactions_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/admin/credits/transactions')
            ->assertStatus(401);
    }

    // ─── Stats endpoint ───────────────────────────────────────────────────────

    public function test_stats_endpoint_is_accessible_by_admin(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/stats')
            ->assertStatus(200)
            ->assertJsonStructure([
                'total_credits_issued',
                'users_with_credits',
                'credits_used_this_month',
            ]);
    }

    public function test_stats_total_credits_issued_reflects_credit_transactions(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload(['amount' => 300, 'type' => 'credit']));

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload(['amount' => 200, 'type' => 'credit']));

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/stats')
            ->assertStatus(200);

        $this->assertEquals(500, $response->json('total_credits_issued'));
    }

    public function test_stats_users_with_credits_counts_positive_balance_users(): void
    {
        $other_client = User::factory()->create(['is_active' => true, 'credit_balance' => 0]);
        $other_client->assignRole('client');

        // Give balance to only the main test client
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/credits/assign', $this->assignPayload(['amount' => 100, 'type' => 'credit']));

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/stats')
            ->assertStatus(200);

        $this->assertEquals(1, $response->json('users_with_credits'));
    }
}
