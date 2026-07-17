<?php

namespace Tests\Feature\Payment;

use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Covers the "defer keyword/link details" flow: checking out without intake
 * details parks the order in `pending_details`, and submitting the details
 * later transitions it to `new_request` and starts the turnaround clock.
 */
class DeferredIntakeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Role::firstOrCreate(['name' => 'client'], [
            'display_name' => 'Client',
            'description'  => 'Regular client',
        ]);

        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 0]);
        $this->client->assignRole('client');

        $this->dr_tier = DrTier::create([
            'id'             => 'dr30',
            'label'          => 'DR 30+',
            'min_dr'         => 30,
            'max_dr'         => 39,
            'traffic_range'  => '5k–10k',
            'word_count'     => 500,
            'price_per_link' => 100.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);
    }

    private function mockStripe(): void
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('verifyPaymentIntent')->andReturn(['verified' => true]);
        $mock->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $mock->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);
        $this->app->instance(StripeService::class, $mock);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'payment_method_id' => 'pi_test_card',
            'total_amount'      => 100.0,
            'session_id'        => (string) Str::uuid(),
            'order_title'       => 'Deferred Order',
            'order_notes'       => null,
            'billing' => [
                'company'     => 'Test Co',
                'address'     => '123 Main St',
                'city'        => 'Boise',
                'state'       => 'ID',
                'country'     => 'US',
                'postal_code' => '83701',
            ],
            'coupon_ids'                 => [],
            'link_building_items'        => null,
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
            'credits_amount'             => 0,
        ], $overrides);
    }

    /** A Link Building item with no keyword/landing page — a single null placeholder. */
    private function deferredLinkBuildingItem(int $quantity = 1): array
    {
        return [
            'dr_tier_id' => $this->dr_tier->id,
            'quantity'   => $quantity,
            'unit_price' => 100.0,
            'placements' => [
                ['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false],
            ],
        ];
    }

    /** A fully-detailed Link Building item. */
    private function completeLinkBuildingItem(int $quantity = 1): array
    {
        return [
            'dr_tier_id' => $this->dr_tier->id,
            'quantity'   => $quantity,
            'unit_price' => 100.0,
            'placements' => array_map(fn ($i) => [
                'row_index'    => $i,
                'keyword'      => 'keyword ' . $i,
                'landing_page' => 'https://example.com/p' . $i,
                'exact_match'  => false,
            ], range(0, $quantity - 1)),
        ];
    }

    public function test_deferred_link_building_checkout_creates_pending_details_order(): void
    {
        $this->mockStripe();

        $payload = $this->basePayload([
            'total_amount'        => 300.0,
            'link_building_items' => [$this->deferredLinkBuildingItem(3)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);
        $order_id = $response->json('data.orders.0.order_id');

        // Order parked in pending_details
        $this->assertDatabaseHas('link_building_orders', [
            'id'     => $order_id,
            'status' => 'pending_details',
        ]);

        // Quantity placements created (padded), none scheduled yet
        $placements = LinkBuildingOrderPlacement::whereHas(
            'orderItem.order',
            fn ($q) => $q->where('id', $order_id)
        )->get();

        $this->assertCount(3, $placements);
        $this->assertTrue($placements->every(fn ($p) => empty($p->estimated_delivery_date)));
        $this->assertTrue($placements->every(fn ($p) => $p->status === 'Pending Details'));
    }

    public function test_complete_link_building_checkout_starts_clock(): void
    {
        $this->mockStripe();

        $payload = $this->basePayload([
            'link_building_items' => [$this->completeLinkBuildingItem(1)],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload);

        $response->assertStatus(200);
        $order_id = $response->json('data.orders.0.order_id');

        $this->assertDatabaseHas('link_building_orders', [
            'id'     => $order_id,
            'status' => 'new_request',
        ]);

        $placement = LinkBuildingOrderPlacement::whereHas(
            'orderItem.order',
            fn ($q) => $q->where('id', $order_id)
        )->first();

        $this->assertNotEmpty($placement->estimated_delivery_date);
        $this->assertEquals('30', (string) $placement->estimated_turnaround_days);
    }

    public function test_submitting_details_transitions_order_and_starts_clock(): void
    {
        $this->mockStripe();

        $payload = $this->basePayload([
            'total_amount'        => 200.0,
            'link_building_items' => [$this->deferredLinkBuildingItem(2)],
        ]);

        $order_id = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload)
            ->json('data.orders.0.order_id');

        $placements = LinkBuildingOrderPlacement::whereHas(
            'orderItem.order',
            fn ($q) => $q->where('id', $order_id)
        )->get();

        $submit_payload = [
            'placements' => $placements->map(fn ($p) => [
                'id'           => $p->id,
                'keyword'      => 'filled keyword',
                'landing_page' => 'https://example.com/filled',
                'exact_match'  => true,
            ])->all(),
        ];

        $response = $this->actingAs($this->client, 'api')
            ->putJson("/api/link-building/orders/{$order_id}/details", $submit_payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'new_request')
            ->assertJsonPath('data.is_pending', false);

        $this->assertDatabaseHas('link_building_orders', [
            'id'     => $order_id,
            'status' => 'new_request',
        ]);

        foreach ($placements as $p) {
            $p->refresh();
            $this->assertEquals('filled keyword', $p->keyword);
            $this->assertNotEmpty($p->estimated_delivery_date);
        }
    }

    public function test_client_cannot_submit_details_for_another_users_order(): void
    {
        $this->mockStripe();

        $order_id = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->basePayload([
                'link_building_items' => [$this->deferredLinkBuildingItem(1)],
            ]))
            ->json('data.orders.0.order_id');

        $placement = LinkBuildingOrderPlacement::whereHas(
            'orderItem.order',
            fn ($q) => $q->where('id', $order_id)
        )->first();

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('client');

        $this->actingAs($other, 'api')
            ->putJson("/api/link-building/orders/{$order_id}/details", [
                'placements' => [[
                    'id'           => $placement->id,
                    'keyword'      => 'hijack',
                    'landing_page' => 'https://evil.com',
                    'exact_match'  => false,
                ]],
            ])
            ->assertStatus(403);
    }

    public function test_deferred_new_content_checkout_is_pending_details(): void
    {
        $this->mockStripe();

        // New Content with no intake rows → pending_details.
        $tier = \App\Models\NewContentTier::create([
            'id'              => 'nc-basic',
            'label'           => 'Basic Article',
            'turnaround_time' => '6 Business Days',
            'price'           => 100.0,
            'is_active'       => true,
        ]);

        $payload = $this->basePayload([
            'link_building_items' => null,
            'new_content_items'   => [[
                'tier_id'    => $tier->id,
                'quantity'   => 1,
                'unit_price' => 100.0,
                'intake_rows' => null,
            ]],
        ]);

        $order_id = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $payload)
            ->assertStatus(200)
            ->json('data.orders.0.order_id');

        $this->assertDatabaseHas('new_content_orders', [
            'id'     => $order_id,
            'status' => 'pending_details',
        ]);
    }
}
