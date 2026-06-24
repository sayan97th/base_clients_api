<?php

namespace Tests\Feature\Credits;

use App\Models\CreditPackage;
use App\Models\CreditPurchase;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCreditPurchaseControllerTest extends TestCase
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

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
    }

    private function makePackage(string $id = 'starter-500'): CreditPackage
    {
        return CreditPackage::firstOrCreate(
            ['id' => $id],
            [
                'name'           => 'Starter 500',
                'credits'        => 500,
                'price'          => 49.99,
                'original_price' => 59.99,
                'discount_pct'   => 17,
                'description'    => '500 credits',
                'is_popular'     => false,
                'is_active'      => true,
            ]
        );
    }

    private function makePurchase(array $overrides = []): CreditPurchase
    {
        $package = $this->makePackage();

        return CreditPurchase::forceCreate(array_merge([
            'user_id'           => $this->client->id,
            'package_id'        => $package->id,
            'package_name'      => $package->name,
            'credits_amount'    => 500,
            'amount_paid'       => 49.99,
            'payment_intent_id' => 'pi_test_' . uniqid(),
            'status'            => 'completed',
        ], $overrides));
    }

    // ─── Authentication and authorization ─────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/admin/credits/purchases')
            ->assertStatus(401);
    }

    public function test_client_role_cannot_access_admin_purchases_endpoint(): void
    {
        $this->actingAs($this->client, 'api')
            ->getJson('/api/admin/credits/purchases')
            ->assertStatus(403);
    }

    public function test_admin_role_can_access_purchases_endpoint(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases')
            ->assertStatus(200);
    }

    // ─── Response structure ────────────────────────────────────────────────────

    public function test_response_has_expected_pagination_keys(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'total',
            ]);
    }

    public function test_purchase_record_has_required_fields(): void
    {
        $this->makePurchase();

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'package_id',
                        'package_name',
                        'credits_amount',
                        'amount_paid',
                        'payment_intent_id',
                        'status',
                        'created_at',
                        'user' => ['id', 'first_name', 'last_name', 'email'],
                    ],
                ],
            ]);
    }

    // ─── Filtering ─────────────────────────────────────────────────────────────

    public function test_returns_all_purchases_without_filters(): void
    {
        $this->makePurchase(['status' => 'completed']);
        $this->makePurchase(['status' => 'pending']);
        $this->makePurchase(['status' => 'failed']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases')
            ->assertStatus(200);

        $this->assertEquals(3, $response->json('total'));
    }

    public function test_filters_purchases_by_status_completed(): void
    {
        $this->makePurchase(['status' => 'completed']);
        $this->makePurchase(['status' => 'pending']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases?status=completed')
            ->assertStatus(200);

        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('completed', $response->json('data.0.status'));
    }

    public function test_filters_purchases_by_status_pending(): void
    {
        $this->makePurchase(['status' => 'completed']);
        $this->makePurchase(['status' => 'pending']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases?status=pending')
            ->assertStatus(200);

        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('pending', $response->json('data.0.status'));
    }

    public function test_filters_purchases_by_date_from(): void
    {
        CreditPurchase::factory()->create([
            'user_id'    => $this->client->id,
            'package_id' => $this->makePackage()->id,
            'status'     => 'completed',
            'created_at' => now()->subDays(10),
        ]);

        CreditPurchase::factory()->create([
            'user_id'    => $this->client->id,
            'package_id' => $this->makePackage()->id,
            'status'     => 'completed',
            'created_at' => now(),
        ]);

        $date_from = now()->subDays(2)->format('Y-m-d');

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/credits/purchases?date_from={$date_from}")
            ->assertStatus(200);

        $this->assertEquals(1, $response->json('total'));
    }

    public function test_filters_purchases_by_date_to(): void
    {
        CreditPurchase::factory()->create([
            'user_id'    => $this->client->id,
            'package_id' => $this->makePackage()->id,
            'status'     => 'completed',
            'created_at' => now()->subDays(10),
        ]);

        CreditPurchase::factory()->create([
            'user_id'    => $this->client->id,
            'package_id' => $this->makePackage()->id,
            'status'     => 'completed',
            'created_at' => now(),
        ]);

        $date_to = now()->subDays(5)->format('Y-m-d');

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/credits/purchases?date_to={$date_to}")
            ->assertStatus(200);

        $this->assertEquals(1, $response->json('total'));
    }

    public function test_search_filters_by_client_first_name(): void
    {
        $other_client = User::factory()->create(['first_name' => 'Alice', 'is_active' => true]);
        $other_client->assignRole('client');

        $this->makePurchase();

        CreditPurchase::create([
            'user_id'           => $other_client->id,
            'package_id'        => $this->makePackage()->id,
            'package_name'      => 'Starter 500',
            'credits_amount'    => 500,
            'amount_paid'       => 49.99,
            'payment_intent_id' => 'pi_other_' . uniqid(),
            'status'            => 'completed',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases?search=Alice')
            ->assertStatus(200);

        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('Alice', $response->json('data.0.user.first_name'));
    }

    public function test_search_filters_by_client_email(): void
    {
        $this->makePurchase();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases?search=' . urlencode($this->client->email))
            ->assertStatus(200);

        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals($this->client->email, $response->json('data.0.user.email'));
    }

    // ─── Pagination ────────────────────────────────────────────────────────────

    public function test_results_are_paginated_with_15_per_page(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makePurchase();
        }

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases')
            ->assertStatus(200);

        $this->assertCount(15, $response->json('data'));
        $this->assertEquals(20, $response->json('total'));
        $this->assertEquals(2, $response->json('last_page'));
    }

    public function test_page_2_returns_remaining_purchases(): void
    {
        for ($i = 0; $i < 18; $i++) {
            $this->makePurchase();
        }

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases?page=2')
            ->assertStatus(200);

        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(2, $response->json('current_page'));
    }

    // ─── Empty state ───────────────────────────────────────────────────────────

    public function test_returns_empty_data_when_no_purchases_exist(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases')
            ->assertStatus(200);

        $this->assertCount(0, $response->json('data'));
        $this->assertEquals(0, $response->json('total'));
    }

    // ─── Data integrity ────────────────────────────────────────────────────────

    public function test_amount_paid_is_returned_as_float(): void
    {
        $this->makePurchase(['amount_paid' => 49.99]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases')
            ->assertStatus(200);

        $this->assertIsFloat($response->json('data.0.amount_paid'));
        $this->assertEquals(49.99, $response->json('data.0.amount_paid'));
    }

    public function test_results_are_ordered_newest_first(): void
    {
        $older  = $this->makePurchase(['created_at' => now()->subDays(2)]);
        $newer  = $this->makePurchase(['created_at' => now()]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases')
            ->assertStatus(200);

        $this->assertEquals($newer->id, $response->json('data.0.id'));
        $this->assertEquals($older->id, $response->json('data.1.id'));
    }

    // ─── Validation ────────────────────────────────────────────────────────────

    public function test_invalid_status_filter_returns_422(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases?status=invalid_status')
            ->assertStatus(422);
    }

    public function test_invalid_date_format_returns_422(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/credits/purchases?date_from=not-a-date')
            ->assertStatus(422);
    }
}
