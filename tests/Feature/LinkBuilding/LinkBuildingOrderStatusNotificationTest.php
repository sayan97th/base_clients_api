<?php

namespace Tests\Feature\LinkBuilding;

use App\Mail\OrderStatusChangeMail;
use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderItem;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LinkBuildingOrderStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);
        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin']);

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function drTier(): DrTier
    {
        return DrTier::firstOrCreate(['id' => 'dr-40'], [
            'label'          => 'DR 40+',
            'traffic_range'  => '1k-5k',
            'word_count'     => 1000,
            'price_per_link' => 150,
        ]);
    }

    private function purchasedPlacement(array $overrides = []): LinkBuildingOrderPlacement
    {
        $order = LinkBuildingOrder::create([
            'user_id'      => $this->client->id,
            'order_title'  => 'Test Order',
            'status'       => 'processing',
            'total_amount' => 150,
            'is_hidden'    => false,
        ]);

        $item = LinkBuildingOrderItem::create([
            'order_id'   => $order->id,
            'dr_tier_id' => $this->drTier()->id,
            'quantity'   => 1,
            'unit_price' => 150,
            'subtotal'   => 150,
        ]);

        return LinkBuildingOrderPlacement::create(array_merge([
            'order_item_id' => $item->id,
            'keyword'       => 'default keyword',
            'landing_page'  => 'https://example.com',
            'link_type'     => 'DR 40+ External',
            'status'        => 'New Request',
            'request_date'  => '01/01/2026',
        ], $overrides));
    }

    private function parentOrderIdFor(LinkBuildingOrderPlacement $placement): string
    {
        return LinkBuildingOrderItem::find($placement->order_item_id)->order_id;
    }

    private function assignedPlacement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . rand(1000, 9999),
            'user_id'      => $this->client->id,
            'keyword'      => 'default keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 40+ External',
            'status'       => 'New Request',
            'request_date' => '01/01/2026',
        ], $overrides));
    }

    // ─── Parent order completion ────────────────────────────────────────────────

    public function test_marking_the_only_placement_live_completes_the_parent_order_and_emails_the_live_links_anchor(): void
    {
        Mail::fake();
        $placement = $this->purchasedPlacement();
        $order_id  = $this->parentOrderIdFor($placement);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['status' => 'Live'])
            ->assertOk();

        $this->assertSame('completed', LinkBuildingOrder::find($order_id)->status);

        Mail::assertQueued(OrderStatusChangeMail::class, function (OrderStatusChangeMail $mail) use ($order_id) {
            $order_url = $mail->content()->with['order_url'];

            return $mail->order_id === $order_id
                && $mail->placement_id === null
                && $order_url === config('app.frontend_url') . "/orders/{$order_id}#live-links";
        });
    }

    public function test_marking_one_of_several_placements_live_moves_the_order_to_processing_not_completed(): void
    {
        Mail::fake();

        $order = LinkBuildingOrder::create([
            'user_id'      => $this->client->id,
            'order_title'  => 'Two Link Order',
            'status'       => 'new_request',
            'total_amount' => 300,
            'is_hidden'    => false,
        ]);
        $item = LinkBuildingOrderItem::create([
            'order_id'   => $order->id,
            'dr_tier_id' => $this->drTier()->id,
            'quantity'   => 2,
            'unit_price' => 150,
            'subtotal'   => 300,
        ]);
        $placement_one = LinkBuildingOrderPlacement::create([
            'order_item_id' => $item->id,
            'keyword'       => 'first keyword',
            'landing_page'  => 'https://example.com/1',
            'status'        => 'New Request',
        ]);
        LinkBuildingOrderPlacement::create([
            'order_item_id' => $item->id,
            'keyword'       => 'second keyword',
            'landing_page'  => 'https://example.com/2',
            'status'        => 'New Request',
        ]);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement_one->id}", ['status' => 'Live'])
            ->assertOk();

        $this->assertSame('processing', $order->fresh()->status);
        Mail::assertQueued(OrderStatusChangeMail::class, fn (OrderStatusChangeMail $mail) => $mail->status === 'processing');
    }

    // ─── Standalone (admin-assigned) placements ─────────────────────────────────

    public function test_standalone_placement_going_live_emails_a_link_to_its_own_placement_page_not_a_nonexistent_order(): void
    {
        Mail::fake();
        $placement = $this->assignedPlacement();

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['status' => 'Live'])
            ->assertOk();

        Mail::assertQueued(OrderStatusChangeMail::class, function (OrderStatusChangeMail $mail) use ($placement) {
            $order_url = $mail->content()->with['order_url'];

            return $mail->placement_id === $placement->id
                && $order_url === config('app.frontend_url') . "/link-building/placements/{$placement->id}"
                && ! str_contains($order_url, '/orders/');
        });
    }

    public function test_standalone_placement_starting_work_emails_processing_status(): void
    {
        Mail::fake();
        $placement = $this->assignedPlacement(['status' => 'New Request']);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['status' => 'In Progress'])
            ->assertOk();

        Mail::assertQueued(
            OrderStatusChangeMail::class,
            fn (OrderStatusChangeMail $mail) => $mail->status === 'processing' && $mail->placement_id === $placement->id
        );
    }
}
