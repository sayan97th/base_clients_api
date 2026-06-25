<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderItem;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkBuildingOrdersDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super admin']);
        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'staff'],       ['display_name' => 'Staff',       'description' => 'Staff user']);
        Role::firstOrCreate(['name' => 'client'],      ['display_name' => 'Client',      'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->client = User::factory()->create([
            'is_active'  => true,
            'first_name' => 'Tyler',
            'last_name'  => 'Smith',
            'company'    => 'Acme Corp',
        ]);
        $this->client->assignRole('client');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function adminPlacement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . rand(1000, 9999),
            'client'       => 'Default Client',
            'keyword'      => 'default keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
        ], $overrides));
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_unauthenticated_search_returns_401(): void
    {
        $this->postJson('/api/admin/link-building-orders/search')
            ->assertStatus(401);
    }

    public function test_client_role_cannot_access_search(): void
    {
        $this->actingAs($this->client, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertStatus(403);
    }

    public function test_unauthenticated_store_returns_401(): void
    {
        $this->postJson('/api/admin/link-building-orders', [])
            ->assertStatus(401);
    }

    public function test_unauthenticated_assignable_clients_returns_401(): void
    {
        $this->getJson('/api/admin/link-building-orders/assignable-clients')
            ->assertStatus(401);
    }

    // ─── Search — response structure ──────────────────────────────────────────

    public function test_search_returns_paginated_response_shape(): void
    {
        $this->adminPlacement(['order_id' => 'BL-1', 'client' => 'Test Co']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'per_page',
                'total',
                'from',
                'to',
            ]);
    }

    public function test_search_row_has_expected_fields(): void
    {
        $this->adminPlacement(['order_id' => 'BL-2', 'client' => 'Field Test Corp']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertStatus(200);

        $row = $response->json('data.0');

        foreach (['id', 'order_id', 'client', 'keyword', 'landing_page', 'status', 'link_type', 'currency'] as $field) {
            $this->assertArrayHasKey($field, $row, "Row should contain field: {$field}");
        }
    }

    public function test_search_returns_admin_created_rows(): void
    {
        $this->adminPlacement(['order_id' => 'BL-3', 'client' => 'Globex Corporation']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('Globex Corporation', $response->json('data.0.client'));
    }

    // ─── Client display name: company name over contact person name ───────────

    public function test_client_purchased_placement_shows_company_name_not_contact_name(): void
    {
        // Client "Tyler Smith" (company: Acme Corp) places an order through the cart.
        $dr_tier = DrTier::create([
            'id'             => 'dr30',
            'label'          => 'DR 30+',
            'traffic_range'  => '1k-10k',
            'word_count'     => 800,
            'price_per_link' => 100.00,
        ]);

        $order = LinkBuildingOrder::create([
            'user_id'                  => $this->client->id,
            'order_title'              => 'Purchased Order',
            'subtotal_before_discount' => 200.0,
            'total_amount'             => 200.0,
            'status'                   => 'processing',
        ]);

        $item = LinkBuildingOrderItem::create([
            'order_id'   => $order->id,
            'dr_tier_id' => $dr_tier->id,
            'quantity'   => 1,
            'unit_price' => 200.0,
            'subtotal'   => 200.0,
        ]);

        // The `client` column is intentionally empty — must derive display name from company.
        LinkBuildingOrderPlacement::create([
            'order_item_id' => $item->id,
            'keyword'       => 'best seo tools',
            'landing_page'  => 'https://acme.com',
            'status'        => 'New Request',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertStatus(200);

        $client_value = $response->json('data.0.client');

        $this->assertSame('Acme Corp', $client_value, 'Company name should be the primary client identifier');
        $this->assertNotSame('Tyler', $client_value, 'Contact person first name must not appear as the primary identifier');
        $this->assertNotSame('Tyler Smith', $client_value, 'Full contact name must not appear as the primary identifier');
    }

    public function test_admin_assigned_placement_shows_company_name_not_contact_name(): void
    {
        // Admin creates a standalone placement and links it to a client user via user_id.
        // The `client` column is empty — company must be derived from user.company.
        LinkBuildingOrderPlacement::create([
            'user_id'      => $this->client->id,
            'keyword'      => 'company seo',
            'landing_page' => 'https://acme.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertStatus(200);

        $client_value = $response->json('data.0.client');

        $this->assertSame('Acme Corp', $client_value);
        $this->assertNotSame('Tyler', $client_value);
    }

    public function test_explicit_client_field_takes_priority_over_derived_company(): void
    {
        // When the admin has manually typed a client name in the `client` column,
        // that value wins over any derived company name.
        LinkBuildingOrderPlacement::create([
            'user_id'      => $this->client->id,
            'client'       => 'Custom Override Name',
            'keyword'      => 'priority test',
            'landing_page' => 'https://override.com',
            'status'       => 'New Request',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertStatus(200);

        $this->assertSame('Custom Override Name', $response->json('data.0.client'));
    }

    public function test_client_without_company_falls_back_to_empty_string(): void
    {
        $client_no_company = User::factory()->create([
            'is_active'  => true,
            'first_name' => 'Jane',
            'last_name'  => 'Doe',
            'company'    => null,
        ]);
        $client_no_company->assignRole('client');

        LinkBuildingOrderPlacement::create([
            'user_id'      => $client_no_company->id,
            'keyword'      => 'fallback test',
            'landing_page' => 'https://jane.com',
            'status'       => 'New Request',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search')
            ->assertStatus(200);

        // No company set and no explicit client value → empty string, not the contact name.
        $this->assertSame('', $response->json('data.0.client'));
        $this->assertNotSame('Jane', $response->json('data.0.client'));
    }

    // ─── Store ────────────────────────────────────────────────────────────────

    public function test_store_creates_placement_with_generated_order_id(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders', [
                'link_type'    => 'DR 30+ External',
                'client'       => 'Acme Corp',
                'keyword'      => 'seo agency',
                'landing_page' => 'https://acme.com',
                'status'       => 'New Request',
                'exact_match'  => 'No',
                'currency'     => 'USD',
            ])
            ->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'order_id', 'client', 'keyword', 'link_type', 'status'],
            ]);

        $this->assertStringStartsWith('BL-', $response->json('data.order_id'));
        $this->assertSame('Acme Corp', $response->json('data.client'));
    }

    public function test_store_generates_sequential_order_ids(): void
    {
        $payload = [
            'link_type'    => 'DR 30+ External',
            'client'       => 'Client A',
            'keyword'      => 'keyword',
            'landing_page' => 'https://example.com',
            'status'       => 'New Request',
            'exact_match'  => 'No',
            'currency'     => 'USD',
        ];

        $first  = $this->actingAs($this->admin, 'api')->postJson('/api/admin/link-building-orders', $payload)->json('data.order_id');
        $second = $this->actingAs($this->admin, 'api')->postJson('/api/admin/link-building-orders', $payload)->json('data.order_id');

        $num_first  = (int) substr($first,  3);
        $num_second = (int) substr($second, 3);

        $this->assertGreaterThan($num_first, $num_second, 'Each new order should receive a higher sequential number');
    }

    public function test_store_auto_sets_request_date_when_omitted(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders', [
                'link_type'    => 'DR 40+ External',
                'client'       => 'Date Test Corp',
                'keyword'      => 'test',
                'landing_page' => 'https://date.com',
                'status'       => 'New Request',
                'exact_match'  => 'No',
                'currency'     => 'USD',
            ])
            ->assertStatus(201);

        $request_date = $response->json('data.request_date');
        $this->assertNotEmpty($request_date);
        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}$/', $request_date, 'Date should be in MM/DD/YYYY format');
    }

    public function test_store_returns_422_when_required_fields_are_missing(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders', [])
            ->assertStatus(422);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function test_update_modifies_existing_placement(): void
    {
        $placement = $this->adminPlacement([
            'order_id' => 'BL-100',
            'client'   => 'Old Name',
            'keyword'  => 'old keyword',
            'status'   => 'New Request',
        ]);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", [
                'client'       => 'Updated Corp',
                'keyword'      => 'updated keyword',
                'landing_page' => 'https://updated.com',
                'link_type'    => 'DR 50+ External',
                'status'       => 'Reviewing',
                'exact_match'  => 'No',
                'currency'     => 'USD',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.client', 'Updated Corp')
            ->assertJsonPath('data.keyword', 'updated keyword')
            ->assertJsonPath('data.status', 'Reviewing');
    }

    public function test_update_returns_404_for_unknown_placement(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/link-building-orders/00000000-0000-0000-0000-000000000000', [
                'client'       => 'X',
                'keyword'      => 'y',
                'landing_page' => 'https://x.com',
                'link_type'    => 'DR 30+ External',
                'status'       => 'New Request',
                'exact_match'  => 'No',
                'currency'     => 'USD',
            ])
            ->assertStatus(404);
    }

    // ─── Destroy ──────────────────────────────────────────────────────────────

    public function test_destroy_deletes_placement_and_returns_success_message(): void
    {
        $placement = $this->adminPlacement(['order_id' => 'BL-200', 'client' => 'To Delete']);

        $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/admin/link-building-orders/{$placement->id}")
            ->assertStatus(200)
            ->assertJsonFragment(['message' => 'Link building order deleted successfully.']);

        $this->assertNull(LinkBuildingOrderPlacement::find($placement->id));
    }

    public function test_destroy_returns_404_for_unknown_placement(): void
    {
        $this->actingAs($this->admin, 'api')
            ->deleteJson('/api/admin/link-building-orders/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    // ─── Search — filters ─────────────────────────────────────────────────────

    public function test_search_filters_by_status(): void
    {
        $this->adminPlacement(['order_id' => 'BL-300', 'status' => 'Live']);
        $this->adminPlacement(['order_id' => 'BL-301', 'status' => 'Cancelled']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', ['status' => 'Live'])
            ->assertStatus(200);

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('Live', $response->json('data.0.status'));
    }

    public function test_search_filters_by_link_type(): void
    {
        $this->adminPlacement(['order_id' => 'BL-310', 'link_type' => 'DR 30+ External']);
        $this->adminPlacement(['order_id' => 'BL-311', 'link_type' => 'DR 70+ External']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', ['link_type' => 'DR 70+ External'])
            ->assertStatus(200);

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('DR 70+ External', $response->json('data.0.link_type'));
    }

    public function test_search_filters_by_client_text(): void
    {
        $this->adminPlacement(['order_id' => 'BL-320', 'client' => 'Stark Industries']);
        $this->adminPlacement(['order_id' => 'BL-321', 'client' => 'Wayne Enterprises']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', ['client' => 'Stark'])
            ->assertStatus(200);

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('Stark Industries', $response->json('data.0.client'));
    }

    public function test_search_filters_by_client_user_id(): void
    {
        // Placement linked to the client user
        LinkBuildingOrderPlacement::create([
            'user_id'      => $this->client->id,
            'keyword'      => 'assigned link',
            'landing_page' => 'https://acme.com',
            'status'       => 'New Request',
        ]);

        // Unrelated admin-created placement
        $this->adminPlacement(['order_id' => 'BL-330', 'client' => 'Other Client']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', [
                'client_user_id' => $this->client->id,
            ])
            ->assertStatus(200);

        $this->assertSame(1, $response->json('total'));
    }

    public function test_search_filters_by_assigned_admin_user(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('staff');

        $this->adminPlacement(['order_id' => 'BL-340', 'assigned_admin_user_id' => $staff->id]);
        $this->adminPlacement(['order_id' => 'BL-341']); // unassigned

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', [
                'assigned_user_id' => $staff->id,
            ])
            ->assertStatus(200);

        $this->assertSame(1, $response->json('total'));
    }

    public function test_search_global_search_matches_keyword_and_client(): void
    {
        $this->adminPlacement(['order_id' => 'BL-350', 'client' => 'Umbrella Corp', 'keyword' => 'biotech']);
        $this->adminPlacement(['order_id' => 'BL-351', 'client' => 'Other', 'keyword' => 'unrelated']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', ['search' => 'Umbrella'])
            ->assertStatus(200);

        $this->assertSame(1, $response->json('total'));
    }

    // ─── Search — pagination ──────────────────────────────────────────────────

    public function test_search_respects_per_page_parameter(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->adminPlacement(['order_id' => "BL-40{$i}"]);
        }

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', ['per_page' => 2])
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(5, $response->json('total'));
        $this->assertSame(3, $response->json('last_page'));
    }

    // ─── Batch update ─────────────────────────────────────────────────────────

    public function test_batch_update_applies_field_to_multiple_rows(): void
    {
        $p1 = $this->adminPlacement(['order_id' => 'BL-500', 'status' => 'New Request']);
        $p2 = $this->adminPlacement(['order_id' => 'BL-501', 'status' => 'New Request']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update', [
                'row_ids' => [$p1->id, $p2->id],
                'updates' => ['status' => 'Reviewing'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('updated_count', 2);

        $this->assertSame('Reviewing', $p1->fresh()->status);
        $this->assertSame('Reviewing', $p2->fresh()->status);
    }

    public function test_batch_update_ignores_non_whitelisted_fields(): void
    {
        $placement = $this->adminPlacement(['order_id' => 'BL-510']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update', [
                'row_ids' => [$placement->id],
                'updates' => ['nonexistent_field' => 'value'],
            ]);

        // All updates were stripped — nothing to update
        $response->assertStatus(422);
    }

    public function test_batch_update_can_assign_client_user_to_rows(): void
    {
        $p1 = $this->adminPlacement(['order_id' => 'BL-520']);
        $p2 = $this->adminPlacement(['order_id' => 'BL-521']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update', [
                'row_ids' => [$p1->id, $p2->id],
                'updates' => ['user_id' => $this->client->id],
            ])
            ->assertStatus(200)
            ->assertJsonPath('updated_count', 2);

        $this->assertEquals($this->client->id, $p1->fresh()->user_id);
        $this->assertEquals($this->client->id, $p2->fresh()->user_id);
    }

    // ─── Assignable clients ───────────────────────────────────────────────────

    public function test_assignable_clients_returns_correct_shape(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/assignable-clients')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'email', 'avatar_url', 'company'],
                ],
            ]);
    }

    public function test_assignable_clients_includes_company_name(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/assignable-clients')
            ->assertStatus(200);

        $entry = collect($response->json('data'))->firstWhere('id', $this->client->id);

        $this->assertNotNull($entry, 'Client user should appear in the assignable clients list');
        $this->assertSame('Acme Corp', $entry['company']);
        $this->assertSame('Tyler Smith', $entry['name']);
    }

    public function test_assignable_clients_does_not_include_admin_users(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/assignable-clients')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->admin->id, $ids, 'Admin users must not appear in the client list');
    }

    public function test_assignable_clients_orders_clients_with_company_before_those_without(): void
    {
        $no_company = User::factory()->create([
            'is_active'  => true,
            'first_name' => 'Alex',
            'last_name'  => 'Zulu',
            'company'    => '',
        ]);
        $no_company->assignRole('client');

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/assignable-clients')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $pos_with_company    = array_search($this->client->id, $ids);
        $pos_without_company = array_search($no_company->id, $ids);

        $this->assertLessThan(
            $pos_without_company,
            $pos_with_company,
            'Clients with a company name should be listed before those without one'
        );
    }

    // ─── Assignable users (admin side) ────────────────────────────────────────

    public function test_assignable_users_returns_correct_shape(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/assignable-users')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'email', 'avatar_url'],
                ],
            ]);
    }

    public function test_assignable_users_includes_admin_users(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/assignable-users')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->admin->id, $ids);
    }

    public function test_assignable_users_does_not_include_client_users(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/assignable-users')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->client->id, $ids, 'Client-role users must not appear in the admin assignable list');
    }

    public function test_assignable_users_includes_staff_role(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('staff');

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/assignable-users')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($staff->id, $ids);
    }
}
