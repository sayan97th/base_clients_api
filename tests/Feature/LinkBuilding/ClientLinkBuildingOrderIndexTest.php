<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for GET /api/link-building/orders.
 *
 * A client reported that the dashboard's "Order History" widget stopped
 * showing months older than the current one, even though the account had
 * two years of orders. The root cause was that the frontend called this
 * endpoint with no page/per_page, silently falling back to a default
 * per_page of 10, so anything older than the 10 most recent orders never
 * reached the widget. The frontend fix now always sends an explicit
 * per_page, so these tests lock down the pagination contract it depends on.
 */
class ClientLinkBuildingOrderIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $other_user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user       = User::factory()->create(['is_active' => true]);
        $this->other_user = User::factory()->create(['is_active' => true]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeOrder(User $user, array $overrides = []): LinkBuildingOrder
    {
        $created_at = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $order = LinkBuildingOrder::create(array_merge([
            'user_id'                  => $user->id,
            'subtotal_before_discount' => 100.0,
            'total_amount'             => 100.0,
            'status'                   => 'completed',
        ], $overrides));

        if ($created_at !== null) {
            $order->created_at = $created_at;
            $order->save();
        }

        return $order;
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/link-building/orders')->assertStatus(401);
    }

    // ─── Pagination contract ────────────────────────────────────────────────

    public function test_default_per_page_is_ten_when_not_specified(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->makeOrder($this->user);
        }

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/link-building/orders')
            ->assertStatus(200);

        $response->assertJsonCount(10, 'data');
        $this->assertSame(15, $response->json('total'));
        $this->assertSame(2, $response->json('last_page'));
    }

    public function test_per_page_can_be_raised_to_cover_a_clients_full_history(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->makeOrder($this->user);
        }

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/link-building/orders?per_page=100')
            ->assertStatus(200);

        $response->assertJsonCount(15, 'data');
        $this->assertSame(1, $response->json('last_page'));
    }

    public function test_per_page_above_two_hundred_is_rejected(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/link-building/orders?per_page=500')
            ->assertStatus(422);
    }

    // ─── Scoping ────────────────────────────────────────────────────────────

    public function test_only_returns_the_authenticated_users_own_orders(): void
    {
        $this->makeOrder($this->user);
        $this->makeOrder($this->other_user);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/link-building/orders?per_page=100')
            ->assertStatus(200);

        $response->assertJsonCount(1, 'data');
    }

    public function test_hidden_orders_are_excluded(): void
    {
        $this->makeOrder($this->user);
        $this->makeOrder($this->user, ['is_hidden' => true]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/link-building/orders?per_page=100')
            ->assertStatus(200);

        $response->assertJsonCount(1, 'data');
    }

    // ─── Ordering and shape used by the dashboard's monthly breakdown ────────

    public function test_orders_are_sorted_newest_first(): void
    {
        $older = $this->makeOrder($this->user, ['created_at' => now()->subMonths(2)]);
        $newer = $this->makeOrder($this->user, ['created_at' => now()]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/link-building/orders?per_page=100')
            ->assertStatus(200);

        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
    }

    public function test_response_shape_includes_the_fields_the_dashboard_needs(): void
    {
        $this->makeOrder($this->user, [
            'created_at'   => '2026-07-15 10:00:00',
            'total_amount' => 250.0,
            'status'       => 'processing',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/link-building/orders?per_page=100')
            ->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'order_title', 'total_amount', 'status',
                    'created_at', 'items_count', 'updates_count', 'last_update_at',
                ],
            ],
            'current_page', 'last_page', 'per_page', 'total',
        ]);

        $this->assertEquals(250.0, $response->json('data.0.total_amount'));
        $this->assertSame('processing', $response->json('data.0.status'));
    }

    public function test_search_filters_by_order_title(): void
    {
        $this->makeOrder($this->user, ['order_title' => 'August link building batch']);
        $this->makeOrder($this->user, ['order_title' => 'Unrelated order']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/link-building/orders?search=August')
            ->assertStatus(200);

        $response->assertJsonCount(1, 'data');
        $this->assertSame('August link building batch', $response->json('data.0.order_title'));
    }
}
