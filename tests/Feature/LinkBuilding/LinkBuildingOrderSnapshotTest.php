<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderSnapshot;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderDetailsService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use Tests\TestCase;

/**
 * Read only "Original Order" copies: one is kept for every Link Building order at
 * checkout, whatever the payment method, and survives later edits of the order.
 */
class LinkBuildingOrderSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);
        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin user']);

        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 500, 'company' => 'Weave']);
        $this->client->assignRole('client');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        DrTier::create([
            'id' => 'dr60', 'label' => 'DR 60+', 'min_dr' => 60, 'max_dr' => 100, 'traffic_range' => '10k+',
            'word_count' => 800, 'price_per_link' => 100.0, 'is_hidden' => false, 'is_active' => true,
        ]);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('verifyPaymentIntent')->andReturn(['verified' => true]);
        $stripe->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $stripe->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);
        $this->app->instance(StripeService::class, $stripe);
    }

    private function linkBuildingItem(): array
    {
        return [
            'dr_tier_id' => 'dr60',
            'quantity'   => 1,
            'unit_price' => 100.0,
            'placements' => [[
                'row_index'    => 0,
                'keyword'      => 'other fatigue icd 10',
                'landing_page' => 'https://www.getweave.com/fatigue-icd-10/',
                'exact_match'  => true,
            ]],
        ];
    }

    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'payment_method_id'          => 'pi_test_card',
            'total_amount'               => 100.0,
            'session_id'                 => (string) Str::uuid(),
            'order_title'                => 'Weave links',
            'order_notes'                => null,
            'billing'                    => [
                'company' => 'Weave', 'address' => '1 Main St', 'city' => 'Lehi',
                'state'   => 'UT', 'country' => 'US', 'postal_code' => '84043',
            ],
            'coupon_ids'                 => [],
            'link_building_items'        => [$this->linkBuildingItem()],
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
            'credits_amount'             => 0,
        ], $overrides);
    }

    private function checkout(array $overrides = []): LinkBuildingOrder
    {
        $order_id = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->checkoutPayload($overrides))
            ->assertOk()
            ->json('data.orders.0.order_id');

        return LinkBuildingOrder::findOrFail($order_id);
    }

    public function test_card_checkout_keeps_the_order_as_submitted(): void
    {
        $order = $this->checkout();

        $snapshot = $order->snapshots()->sole();
        $this->assertSame(LinkBuildingOrderSnapshot::TYPE_PLACED, $snapshot->type);
        $this->assertSame(LinkBuildingOrderSnapshot::PAYMENT_CREDIT_CARD, $snapshot->payment_method);
        $this->assertFalse($snapshot->is_backfilled);
        $this->assertSame($this->client->id, $snapshot->captured_by_id);

        $placement = $snapshot->payload['items'][0]['placements'][0];
        $this->assertSame('other fatigue icd 10', $placement['keyword']);
        $this->assertSame('https://www.getweave.com/fatigue-icd-10/', $placement['landing_page']);
        $this->assertTrue($placement['exact_match']);
        $this->assertSame('DR 60+ External', $placement['link_type']);
        $this->assertStringStartsWith('BL-', $placement['order_id']);
        $this->assertSame('Lehi', $snapshot->payload['billing']['city']);
    }

    public function test_credits_checkout_records_the_account_balance_payment(): void
    {
        $order = $this->checkout(['payment_method_id' => 'credits_pay_100']);

        $this->assertSame(LinkBuildingOrderSnapshot::PAYMENT_ACCOUNT_BALANCE, $order->snapshots()->sole()->payment_method);
    }

    public function test_pay_later_checkout_keeps_the_order_as_submitted(): void
    {
        $order_id = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', [
                'deferred_payment'           => true,
                'total_amount'               => 100.0,
                'session_id'                 => (string) Str::uuid(),
                'order_title'                => 'Pay Later Order',
                'order_notes'                => null,
                'coupon_ids'                 => [],
                'link_building_items'        => [$this->linkBuildingItem()],
                'content_optimization_items' => null,
                'new_content_items'          => null,
                'content_brief_items'        => null,
            ])
            ->assertOk()
            ->json('data.orders.0.order_id');

        $snapshot = LinkBuildingOrder::findOrFail($order_id)->snapshots()->sole();
        $this->assertSame(LinkBuildingOrderSnapshot::PAYMENT_PAY_LATER_INVOICE, $snapshot->payment_method);
        $this->assertSame('other fatigue icd 10', $snapshot->payload['items'][0]['placements'][0]['keyword']);
    }

    public function test_the_original_copy_survives_edits_on_the_link_building_orders_table(): void
    {
        $order     = $this->checkout();
        $placement = LinkBuildingOrderPlacement::whereNotNull('order_item_id')->sole();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [['id' => $placement->id, 'fields' => ['client' => 'Mather', 'keyword' => 'senior living']]],
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/orders/{$order->id}/original")
            ->assertOk()
            ->assertJsonPath('data.order.id', $order->id)
            ->assertJsonPath('data.snapshots.0.payload.items.0.placements.0.keyword', 'other fatigue icd 10')
            ->assertJsonPath('data.snapshots.0.payload.items.0.placements.0.client', 'Weave')
            ->assertJsonPath("data.current_placements.{$placement->id}.keyword", 'senior living')
            ->assertJsonPath("data.current_placements.{$placement->id}.client", 'Mather');
    }

    public function test_submitting_deferred_details_keeps_another_copy(): void
    {
        $order     = $this->checkout(['defer_details' => true]);
        $placement = LinkBuildingOrderPlacement::whereNotNull('order_item_id')->sole();

        app(OrderDetailsService::class)->submitLinkBuildingDetails($order, [[
            'id' => $placement->id, 'keyword' => 'dental front office training', 'landing_page' => 'https://www.getweave.com/x/', 'exact_match' => false,
        ]]);

        $snapshots = $order->snapshots()->get();
        $this->assertSame([LinkBuildingOrderSnapshot::TYPE_PLACED, LinkBuildingOrderSnapshot::TYPE_DETAILS_SUBMITTED], $snapshots->pluck('type')->all());
        $this->assertSame('dental front office training', $snapshots[1]->payload['items'][0]['placements'][0]['keyword']);
    }

    public function test_a_copy_cannot_be_changed_or_deleted(): void
    {
        $snapshot = $this->checkout()->snapshots()->sole();

        try {
            $snapshot->update(['payment_method' => 'Edited']);
            $this->fail('A snapshot update must throw.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $snapshot->delete();
    }

    public function test_backfill_gives_older_orders_a_flagged_copy_once(): void
    {
        $order = LinkBuildingOrder::create(['user_id' => $this->client->id, 'total_amount' => 100, 'status' => 'processing']);
        $order->items()->create(['dr_tier_id' => 'dr60', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100])
            ->placements()->create(['order_id' => 'BL-25687', 'row_index' => 0, 'keyword' => 'legacy']);

        $this->artisan('link-building:backfill-order-snapshots')->assertSuccessful();
        $this->artisan('link-building:backfill-order-snapshots')->assertSuccessful();

        $snapshot = $order->snapshots()->sole();
        $this->assertTrue($snapshot->is_backfilled);
        $this->assertSame('BL-25687', $snapshot->payload['items'][0]['placements'][0]['order_id']);
    }

    public function test_only_link_building_orders_have_an_original_view(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/orders/' . Str::uuid() . '/original')
            ->assertNotFound();
    }

    public function test_clients_cannot_open_the_admin_original_view(): void
    {
        $order = $this->checkout();

        $this->actingAs($this->client, 'api')
            ->getJson("/api/admin/orders/{$order->id}/original")
            ->assertForbidden();
    }
}
