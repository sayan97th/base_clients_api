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

class OrderPlacementsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function purchasedPlacement(array $overrides = []): LinkBuildingOrderPlacement
    {
        $dr_tier = DrTier::create([
            'id'             => 'dr-40',
            'label'          => 'DR 40+',
            'traffic_range'  => '1k-5k',
            'word_count'     => 1000,
            'price_per_link' => 150,
        ]);

        $order = LinkBuildingOrder::create([
            'user_id'     => $this->client->id,
            'status'      => 'completed',
            'total_amount'=> 150,
            'is_hidden'   => false,
        ]);

        $item = LinkBuildingOrderItem::create([
            'order_id'   => $order->id,
            'dr_tier_id' => $dr_tier->id,
            'quantity'   => 1,
            'unit_price' => 150,
            'subtotal'   => 150,
        ]);

        return LinkBuildingOrderPlacement::create(array_merge([
            'order_item_id' => $item->id,
            'keyword'       => 'default keyword',
            'landing_page'  => 'https://example.com',
            'link_type'     => 'DR 40+ External',
            'status'        => 'Live',
            'request_date'  => '01/01/2026',
        ], $overrides));
    }

    private function assignedPlacement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . rand(1000, 9999),
            'user_id'      => $this->client->id,
            'keyword'      => 'default keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 40+ External',
            'status'       => 'Live',
            'request_date' => '01/01/2026',
        ], $overrides));
    }

    // ─── Completed date ───────────────────────────────────────────────────────

    public function test_purchased_placement_completed_date_reflects_live_link_date(): void
    {
        $this->purchasedPlacement(['live_link_date' => '07/13/2026']);

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/link-building/order-placements')
            ->assertOk();

        $this->assertSame('07/13/2026', $response->json('data.0.completed_date'));
    }

    public function test_purchased_placement_without_live_link_date_has_no_completed_date(): void
    {
        $this->purchasedPlacement(['live_link_date' => null]);

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/link-building/order-placements')
            ->assertOk();

        $this->assertNull($response->json('data.0.completed_date'));
    }

    public function test_admin_assigned_placement_completed_date_reflects_live_link_date(): void
    {
        $this->assignedPlacement(['live_link_date' => '07/13/2026']);

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/link-building/order-placements')
            ->assertOk();

        $this->assertSame('07/13/2026', $response->json('data.0.completed_date'));
    }

    public function test_export_includes_completed_date_from_live_link_date(): void
    {
        $this->purchasedPlacement(['live_link_date' => '07/13/2026']);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/link-building/order-placements/export', ['format' => 'json'])
            ->assertOk();

        $this->assertSame('07/13/2026', $response->json('data.0.completed_date'));
    }
}
