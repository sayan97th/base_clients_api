<?php

namespace Tests\Feature\Order;

use App\Models\ContentBriefOrder;
use App\Models\ContentBriefTier;
use App\Models\ContentOptimizationOrder;
use App\Models\ContentOptimizationTier;
use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Models\NewContentTier;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the client- and admin-side "fill in details later" submission endpoints
 * for all four product types: writing the deferred intake details transitions the
 * order out of pending_details (when complete) and starts the Link Building clock.
 */
class OrderDetailsSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client']);

        $this->client = User::factory()->create(['is_active' => true, 'company' => 'Acme Co']);
        $this->client->assignRole('client');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        DrTier::create([
            'id' => 'dr30', 'label' => 'DR 30+', 'min_dr' => 30, 'max_dr' => 39,
            'traffic_range' => '5k', 'word_count' => 500, 'price_per_link' => 100.0,
            'is_hidden' => false, 'is_active' => true,
        ]);
        NewContentTier::create([
            'id' => 'nc', 'label' => 'Article', 'turnaround_time' => '6 Business Days',
            'price' => 100.0, 'is_active' => true,
        ]);
        ContentOptimizationTier::create([
            'id' => 'co', 'label' => 'Optimize', 'word_count_range' => '500-800',
            'turnaround_days' => 5, 'price' => 100.0, 'is_active' => true,
        ]);
        ContentBriefTier::create([
            'id' => 'cb', 'label' => 'Brief', 'turnaround_days' => 5,
            'price' => 100.0, 'is_active' => true,
        ]);
    }

    // ── Fixtures: pending_details orders created directly ────────────────────

    private function pendingLinkBuildingOrder(int $placements = 2): LinkBuildingOrder
    {
        $order = LinkBuildingOrder::create([
            'user_id' => $this->client->id, 'order_title' => 'LB',
            'subtotal_before_discount' => 200.0, 'total_amount' => 200.0,
            'status' => 'pending_details',
        ]);
        $item = $order->items()->create([
            'dr_tier_id' => 'dr30', 'quantity' => $placements, 'unit_price' => 100.0, 'subtotal' => 100.0 * $placements,
        ]);
        for ($i = 0; $i < $placements; $i++) {
            $item->placements()->create([
                'order_id' => 'BL-TEST-' . $i, 'row_index' => $i, 'exact_match' => false,
                'status' => 'Pending Details', 'user_id' => $this->client->id,
            ]);
        }

        return $order;
    }

    private function pendingNewContentOrder(): NewContentOrder
    {
        $order = NewContentOrder::create([
            'user_id' => $this->client->id, 'subtotal_before_discount' => 100.0,
            'total_amount' => 100.0, 'status' => 'pending_details',
        ]);
        $order->items()->create(['tier_id' => 'nc', 'quantity' => 1, 'unit_price' => 100.0, 'subtotal' => 100.0]);

        return $order;
    }

    private function pendingContentOptimizationOrder(): ContentOptimizationOrder
    {
        $order = ContentOptimizationOrder::create([
            'user_id' => $this->client->id, 'subtotal_before_discount' => 100.0,
            'total_amount' => 100.0, 'status' => 'pending_details',
        ]);
        $order->items()->create(['tier_id' => 'co', 'quantity' => 1, 'unit_price' => 100.0, 'subtotal' => 100.0]);

        return $order;
    }

    private function pendingContentBriefOrder(): ContentBriefOrder
    {
        $order = ContentBriefOrder::create([
            'user_id' => $this->client->id, 'subtotal_before_discount' => 100.0,
            'total_amount' => 100.0, 'status' => 'pending_details',
        ]);
        $order->items()->create(['tier_id' => 'cb', 'quantity' => 1, 'unit_price' => 100.0, 'subtotal' => 100.0]);

        return $order;
    }

    // ── Client: Link Building ────────────────────────────────────────────────

    public function test_client_submits_link_building_details_and_starts_clock(): void
    {
        $order      = $this->pendingLinkBuildingOrder(2);
        $placements = $order->items->flatMap->placements;

        $response = $this->actingAs($this->client, 'api')->putJson(
            "/api/link-building/orders/{$order->id}/details",
            ['placements' => $placements->map(fn ($p) => [
                'id' => $p->id, 'keyword' => 'seo', 'landing_page' => 'https://a.com', 'exact_match' => true,
            ])->all()]
        );

        $response->assertStatus(200)->assertJsonPath('data.status', 'new_request');

        $placements->each(function ($p) {
            $p->refresh();
            $this->assertSame('seo', $p->keyword);
            $this->assertTrue((bool) $p->exact_match);
            $this->assertNotEmpty($p->estimated_delivery_date);
        });
    }

    public function test_partial_link_building_details_stay_pending(): void
    {
        $order      = $this->pendingLinkBuildingOrder(2);
        $placements  = $order->items->flatMap->placements;

        // Only the first placement gets details.
        $rows = $placements->values()->map(fn ($p, $i) => [
            'id' => $p->id,
            'keyword' => $i === 0 ? 'seo' : null,
            'landing_page' => $i === 0 ? 'https://a.com' : null,
            'exact_match' => false,
        ])->all();

        $this->actingAs($this->client, 'api')
            ->putJson("/api/link-building/orders/{$order->id}/details", ['placements' => $rows])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending_details')
            ->assertJsonPath('data.is_pending', true);
    }

    // ── Client: New Content / Content Optimization / Content Brief ───────────

    public function test_client_submits_new_content_details(): void
    {
        $order = $this->pendingNewContentOrder();
        $item  = $order->items->first();

        $this->actingAs($this->client, 'api')->putJson(
            "/api/new-content/orders/{$order->id}/details",
            ['items' => [[
                'item_id' => $item->id,
                'intake_rows' => [
                    ['keyword_phrase' => 'coffee', 'secondary_keywords' => null, 'type_of_content' => 'Blog Article', 'notes' => null],
                ],
            ]]]
        )->assertStatus(200)->assertJsonPath('data.status', 'new_request');

        $this->assertDatabaseHas('new_content_intake_rows', ['keyword_phrase' => 'coffee', 'type_of_content' => 'Blog Article']);
    }

    public function test_client_submits_content_optimization_details(): void
    {
        $order = $this->pendingContentOptimizationOrder();
        $item  = $order->items->first();

        $this->actingAs($this->client, 'api')->putJson(
            "/api/content-optimization/orders/{$order->id}/details",
            ['items' => [[
                'item_id' => $item->id,
                'intake_rows' => [
                    ['primary_keyword' => 'shoes', 'secondary_keywords' => null, 'content_page_url' => 'https://a.com/p', 'notes' => null],
                ],
            ]]]
        )->assertStatus(200)->assertJsonPath('data.status', 'new_request');

        $this->assertDatabaseHas('content_optimization_intake_rows', ['primary_keyword' => 'shoes']);
    }

    public function test_client_submits_content_brief_details(): void
    {
        $order = $this->pendingContentBriefOrder();
        $item  = $order->items->first();

        $this->actingAs($this->client, 'api')->putJson(
            "/api/content-briefs/orders/{$order->id}/details",
            ['items' => [[
                'item_id' => $item->id,
                'intake_rows' => [
                    ['primary_keyword' => 'widgets', 'secondary_keywords' => null, 'content_page_url' => 'https://a.com/w', 'notes' => 'note'],
                ],
            ]]]
        )->assertStatus(200)->assertJsonPath('data.status', 'new_request');

        $this->assertDatabaseHas('content_brief_intake_rows', ['primary_keyword' => 'widgets']);
    }

    public function test_partial_new_content_details_stay_pending(): void
    {
        $order = $this->pendingNewContentOrder();
        $item  = $order->items->first();

        // keyword present but type_of_content missing → incomplete.
        $this->actingAs($this->client, 'api')->putJson(
            "/api/new-content/orders/{$order->id}/details",
            ['items' => [[
                'item_id' => $item->id,
                'intake_rows' => [
                    ['keyword_phrase' => 'coffee', 'secondary_keywords' => null, 'type_of_content' => null, 'notes' => null],
                ],
            ]]]
        )->assertStatus(200)->assertJsonPath('data.status', 'pending_details');
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    public function test_client_cannot_submit_details_for_in_progress_order(): void
    {
        $order = $this->pendingNewContentOrder();
        $order->update(['status' => 'processing']);
        $item = $order->items->first();

        $this->actingAs($this->client, 'api')->putJson(
            "/api/new-content/orders/{$order->id}/details",
            ['items' => [['item_id' => $item->id, 'intake_rows' => []]]]
        )->assertStatus(422);
    }

    public function test_client_cannot_submit_details_for_another_users_order(): void
    {
        $order  = $this->pendingLinkBuildingOrder(1);
        $other  = User::factory()->create(['is_active' => true]);
        $other->assignRole('client');
        $placement = $order->items->flatMap->placements->first();

        $this->actingAs($other, 'api')->putJson(
            "/api/link-building/orders/{$order->id}/details",
            ['placements' => [['id' => $placement->id, 'keyword' => 'x', 'landing_page' => 'https://x.com', 'exact_match' => false]]]
        )->assertStatus(403);
    }

    public function test_unauthenticated_details_submission_is_rejected(): void
    {
        $order = $this->pendingNewContentOrder();
        $item  = $order->items->first();

        $this->putJson(
            "/api/new-content/orders/{$order->id}/details",
            ['items' => [['item_id' => $item->id, 'intake_rows' => []]]]
        )->assertStatus(401);
    }

    // ── Admin ──────────────────────────────────────────────────────────────

    public function test_admin_submits_link_building_details_on_behalf(): void
    {
        $order      = $this->pendingLinkBuildingOrder(1);
        $placement  = $order->items->flatMap->placements->first();

        $this->actingAs($this->admin, 'api')->putJson(
            "/api/admin/orders/{$order->id}/link-building-details",
            ['placements' => [['id' => $placement->id, 'keyword' => 'admin kw', 'landing_page' => 'https://a.com', 'exact_match' => true]]]
        )->assertStatus(200)->assertJsonPath('data.status', 'new_request');

        $this->assertDatabaseHas('link_building_order_placements', ['id' => $placement->id, 'keyword' => 'admin kw']);
    }

    public function test_admin_submits_new_content_details_on_behalf(): void
    {
        $order = $this->pendingNewContentOrder();
        $item  = $order->items->first();

        $this->actingAs($this->admin, 'api')->putJson(
            "/api/admin/orders/{$order->id}/new-content-details",
            ['items' => [[
                'item_id' => $item->id,
                'intake_rows' => [['keyword_phrase' => 'tea', 'secondary_keywords' => null, 'type_of_content' => 'Blog Article', 'notes' => null]],
            ]]]
        )->assertStatus(200)->assertJsonPath('data.status', 'new_request');
    }

    public function test_admin_can_edit_details_of_in_progress_order_without_changing_status(): void
    {
        $order = $this->pendingContentOptimizationOrder();
        $order->update(['status' => 'processing']);
        $item = $order->items->first();

        $this->actingAs($this->admin, 'api')->putJson(
            "/api/admin/orders/{$order->id}/content-optimization-details",
            ['items' => [[
                'item_id' => $item->id,
                'intake_rows' => [['primary_keyword' => 'edited', 'secondary_keywords' => null, 'content_page_url' => 'https://a.com', 'notes' => null]],
            ]]]
        )->assertStatus(200)->assertJsonPath('data.status', 'processing');

        $this->assertDatabaseHas('content_optimization_intake_rows', ['primary_keyword' => 'edited']);
    }

    public function test_non_admin_cannot_use_admin_details_endpoint(): void
    {
        $order     = $this->pendingLinkBuildingOrder(1);
        $placement = $order->items->flatMap->placements->first();

        $this->actingAs($this->client, 'api')->putJson(
            "/api/admin/orders/{$order->id}/link-building-details",
            ['placements' => [['id' => $placement->id, 'keyword' => 'x', 'landing_page' => 'https://x.com', 'exact_match' => false]]]
        )->assertStatus(403);
    }
}
