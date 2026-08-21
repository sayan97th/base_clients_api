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
        $dr_tier = DrTier::firstOrCreate(['id' => 'dr-40'], [
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

    public function test_admin_assigned_placement_without_live_link_date_has_no_completed_date(): void
    {
        $this->assignedPlacement(['live_link_date' => null]);

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/link-building/order-placements')
            ->assertOk();

        $this->assertNull($response->json('data.0.completed_date'));
    }

    public function test_export_includes_completed_date_from_live_link_date(): void
    {
        $this->purchasedPlacement(['live_link_date' => '07/13/2026']);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/link-building/order-placements/export', ['format' => 'json'])
            ->assertOk();

        $this->assertSame('07/13/2026', $response->json('data.0.completed_date'));
    }

    public function test_csv_export_post_includes_completed_date_column_and_value(): void
    {
        $this->purchasedPlacement(['live_link_date' => '07/13/2026']);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/link-building/order-placements/export', ['format' => 'csv'])
            ->assertOk();

        $rows = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
        $header = $rows[0];
        $data_row = $rows[1];

        $completed_date_index = array_search('Completed Date', $header, true);

        $this->assertNotFalse($completed_date_index, 'CSV header is missing the Completed Date column.');
        $this->assertSame('07/13/2026', $data_row[$completed_date_index]);
    }

    public function test_csv_export_get_includes_completed_date_column_and_value(): void
    {
        $this->purchasedPlacement(['live_link_date' => '07/13/2026']);

        $response = $this->actingAs($this->client, 'api')
            ->get('/api/link-building/order-placements/export')
            ->assertOk();

        $rows = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
        $header = $rows[0];
        $data_row = $rows[1];

        $completed_date_index = array_search('Completed Date', $header, true);

        $this->assertNotFalse($completed_date_index, 'CSV header is missing the Completed Date column.');
        $this->assertSame('07/13/2026', $data_row[$completed_date_index]);
    }

    public function test_sort_by_completed_date_orders_placements_by_live_link_date(): void
    {
        $this->purchasedPlacement(['keyword' => 'earlier', 'live_link_date' => '01/01/2026']);
        $this->purchasedPlacement(['keyword' => 'later', 'live_link_date' => '12/31/2026']);

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/link-building/order-placements?sort_by=completed_date&sort_direction=asc')
            ->assertOk();

        $this->assertSame('earlier', $response->json('data.0.keyword'));
        $this->assertSame('later', $response->json('data.1.keyword'));
    }

    public function test_sort_by_completed_date_places_rows_without_a_live_link_date_last(): void
    {
        $this->purchasedPlacement(['keyword' => 'no live link date', 'live_link_date' => null]);
        $this->purchasedPlacement(['keyword' => 'has live link date', 'live_link_date' => '01/01/2026']);

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/link-building/order-placements?sort_by=completed_date&sort_direction=desc')
            ->assertOk();

        $this->assertSame('has live link date', $response->json('data.0.keyword'));
        $this->assertSame('no live link date', $response->json('data.1.keyword'));
    }

    // ─── Authorization / scoping ──────────────────────────────────────────────

    public function test_client_cannot_see_another_clients_placement_completed_date(): void
    {
        $other_client = User::factory()->create(['is_active' => true]);
        $other_client->assignRole('client');

        $dr_tier = DrTier::firstOrCreate(['id' => 'dr-50'], [
            'label'          => 'DR 50+',
            'traffic_range'  => '1k-5k',
            'word_count'     => 1000,
            'price_per_link' => 150,
        ]);

        $other_order = LinkBuildingOrder::create([
            'user_id'      => $other_client->id,
            'status'       => 'completed',
            'total_amount' => 150,
            'is_hidden'    => false,
        ]);

        $other_item = LinkBuildingOrderItem::create([
            'order_id'   => $other_order->id,
            'dr_tier_id' => $dr_tier->id,
            'quantity'   => 1,
            'unit_price' => 150,
            'subtotal'   => 150,
        ]);

        LinkBuildingOrderPlacement::create([
            'order_item_id'  => $other_item->id,
            'keyword'        => 'not mine',
            'landing_page'   => 'https://example.com',
            'link_type'      => 'DR 50+ External',
            'status'         => 'Live',
            'request_date'   => '01/01/2026',
            'live_link_date' => '07/13/2026',
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/link-building/order-placements')
            ->assertOk();

        $this->assertSame(0, $response->json('total'));
    }
}
