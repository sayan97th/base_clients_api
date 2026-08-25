<?php

namespace Tests\Feature\Admin\Order;

use App\Models\ContentOptimizationOrder;
use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderBilling;
use App\Models\LinkBuildingOrderCoupon;
use App\Models\LinkBuildingOrderItem;
use App\Models\LinkBuildingOrderUpdate;
use App\Models\OrderReport;
use App\Models\OrderReportRow;
use App\Models\OrderReportTable;
use App\Models\OrderSessionComment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $super_admin;
    private User $admin;
    private User $staff;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super Admin']);
        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'staff'],       ['display_name' => 'Staff',       'description' => 'Staff']);
        Role::firstOrCreate(['name' => 'client'],      ['display_name' => 'Client',      'description' => 'Client']);

        $this->super_admin = User::factory()->create(['is_active' => true]);
        $this->admin       = User::factory()->create(['is_active' => true]);
        $this->staff       = User::factory()->create(['is_active' => true]);
        $this->client      = User::factory()->create(['is_active' => true]);

        $this->super_admin->assignRole('super_admin');
        $this->admin->assignRole('admin');
        $this->staff->assignRole('staff');
        $this->client->assignRole('client');
    }

    private function makeDrTier(string $id = 'dr-50'): DrTier
    {
        return DrTier::firstOrCreate(['id' => $id], [
            'label'          => 'DR 50+',
            'traffic_range'  => '1k-5k',
            'word_count'     => 500,
            'price_per_link' => 100.0,
        ]);
    }

    private function makeLinkBuildingOrder(array $overrides = []): LinkBuildingOrder
    {
        return LinkBuildingOrder::create(array_merge([
            'user_id'                  => $this->client->id,
            'order_title'              => 'Test Link Building Order',
            'order_notes'              => null,
            'subtotal_before_discount' => 100.0,
            'total_amount'             => 100.0,
            'status'                   => 'processing',
            'payment_intent_id'        => 'pi_test_123',
            'session_id'               => null,
            'session_title'            => null,
        ], $overrides));
    }

    private function makeContentOptimizationOrder(array $overrides = []): ContentOptimizationOrder
    {
        return ContentOptimizationOrder::create(array_merge([
            'user_id'                  => $this->client->id,
            'order_title'              => 'Test Content Optimization Order',
            'order_notes'              => null,
            'subtotal_before_discount' => 80.0,
            'total_amount'             => 80.0,
            'status'                   => 'processing',
            'payment_intent_id'        => 'pi_test_456',
            'session_id'               => null,
            'session_title'            => null,
        ], $overrides));
    }

    private function makeInvoiceForOrder(string $order_id, array $overrides = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'unique_id'       => strtoupper(bin2hex(random_bytes(4))),
            'invoice_number'  => 'BSM-' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id'         => $this->client->id,
            'order_id'        => $order_id,
            'status'          => 'paid',
            'payment_method'  => 'Credit Card',
            'currency_type'   => 'usd',
            'subtotal_amount' => 100.0,
            'discount_amount' => 0.0,
            'discount_type'   => null,
            'total_amount'    => 100.0,
            'credit_amount'   => 0.0,
            'date_issued'     => now(),
            'date_due'        => now()->addDays(30),
        ], $overrides));

        return $invoice;
    }

    public function test_admin_can_delete_a_link_building_order_and_everything_it_owns(): void
    {
        $order = $this->makeLinkBuildingOrder(['session_id' => 'session-solo']);

        $dr_tier = $this->makeDrTier();
        $item = LinkBuildingOrderItem::create([
            'order_id'   => $order->id,
            'dr_tier_id' => $dr_tier->id,
            'quantity'   => 1,
            'unit_price' => 100.0,
            'subtotal'   => 100.0,
        ]);

        $billing = LinkBuildingOrderBilling::create([
            'order_id' => $order->id,
            'company'  => 'Acme Inc',
            'address'  => '123 Main St',
            'city'     => 'Austin',
            'state'    => 'TX',
            'country'  => 'US',
            'postal_code' => '78701',
        ]);

        $coupon = Coupon::create([
            'code'           => 'TESTCOUPON',
            'name'           => 'Test Coupon',
            'discount_type'  => 'fixed_amount',
            'discount_value' => 10.0,
            'applies_to'     => 'all',
            'expires_at'     => now()->addYear(),
        ]);
        $order_coupon = LinkBuildingOrderCoupon::create([
            'order_id'        => $order->id,
            'coupon_id'       => $coupon->id,
            'discount_amount' => 10.0,
        ]);

        $report = OrderReport::create(['order_id' => $order->id]);
        $table = OrderReportTable::create([
            'report_id' => $report->id,
            'title'     => 'Placements',
        ]);
        $row = OrderReportRow::create([
            'table_id'     => $table->id,
            'order_number' => 'ON-1',
            'keyword'      => 'best shoes',
            'landing_page' => 'https://example.com',
        ]);

        $update = LinkBuildingOrderUpdate::create([
            'order_id'      => $order->id,
            'created_by_id' => $this->admin->id,
            'title'         => 'Status changed',
            'message'       => 'Order moved to processing.',
        ]);

        $order_comment = OrderSessionComment::create([
            'session_id'       => 'session-solo',
            'order_id'         => $order->id,
            'user_id'          => $this->admin->id,
            'content'          => 'Comment scoped to this order.',
            'is_admin_comment' => true,
        ]);
        $session_comment = OrderSessionComment::create([
            'session_id'       => 'session-solo',
            'order_id'         => null,
            'user_id'          => $this->admin->id,
            'content'          => 'General session-level comment.',
            'is_admin_comment' => true,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/admin/orders/{$order->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('link_building_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('link_building_order_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('link_building_order_billing', ['id' => $billing->id]);
        $this->assertDatabaseMissing('link_building_order_coupons', ['id' => $order_coupon->id]);
        $this->assertDatabaseMissing('order_reports', ['id' => $report->id]);
        $this->assertDatabaseMissing('order_report_tables', ['id' => $table->id]);
        $this->assertDatabaseMissing('order_report_rows', ['id' => $row->id]);
        $this->assertDatabaseMissing('order_updates', ['id' => $update->id]);
        $this->assertDatabaseMissing('order_session_comments', ['id' => $order_comment->id]);

        // The coupon definition itself and unrelated session-level comment are not order-owned.
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
        $this->assertDatabaseHas('order_session_comments', ['id' => $session_comment->id]);
    }

    public function test_admin_can_delete_a_content_optimization_order(): void
    {
        $order = $this->makeContentOptimizationOrder();

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/admin/orders/{$order->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('content_optimization_orders', ['id' => $order->id]);
    }

    public function test_deleting_an_order_detaches_its_invoice_instead_of_deleting_it(): void
    {
        $order = $this->makeLinkBuildingOrder();
        $invoice = $this->makeInvoiceForOrder($order->id);
        $line_item = InvoiceLineItem::create([
            'invoice_id' => $invoice->id,
            'order_id'   => $order->id,
            'item_name'  => 'Link Building Package',
            'price'      => 100.0,
            'quantity'   => 1,
            'discount_percent' => 0,
            'item_total' => 100.0,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/admin/orders/{$order->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('link_building_orders', ['id' => $order->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'order_id' => null]);
        $this->assertDatabaseHas('invoice_line_items', ['id' => $line_item->id]);
    }

    public function test_deleting_one_order_in_a_multi_product_session_preserves_its_sibling_and_the_shared_invoice(): void
    {
        $session_id = 'shared-session-1';

        $order_one = $this->makeLinkBuildingOrder(['session_id' => $session_id, 'session_title' => 'Multi-Product Purchase']);
        $order_two = $this->makeContentOptimizationOrder(['session_id' => $session_id, 'session_title' => 'Multi-Product Purchase']);

        // The invoice belongs to order_one via order_id, but its line items cover both orders.
        $invoice = $this->makeInvoiceForOrder($order_one->id, ['total_amount' => 180.0, 'subtotal_amount' => 180.0]);
        $line_item_one = InvoiceLineItem::create([
            'invoice_id'  => $invoice->id,
            'order_id'    => $order_one->id,
            'product_type'=> 'link_building',
            'item_name'   => 'Link Building Package',
            'price'       => 100.0,
            'quantity'    => 1,
            'discount_percent' => 0,
            'item_total'  => 100.0,
        ]);
        $line_item_two = InvoiceLineItem::create([
            'invoice_id'  => $invoice->id,
            'order_id'    => $order_two->id,
            'product_type'=> 'content_optimization',
            'item_name'   => 'Content Optimization Package',
            'price'       => 80.0,
            'quantity'    => 1,
            'discount_percent' => 0,
            'item_total'  => 80.0,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/admin/orders/{$order_one->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('link_building_orders', ['id' => $order_one->id]);
        // The sibling order in the same session must survive untouched.
        $this->assertDatabaseHas('content_optimization_orders', ['id' => $order_two->id]);
        // The shared invoice survives, only detached from the deleted order.
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'order_id' => null]);
        $this->assertDatabaseHas('invoice_line_items', ['id' => $line_item_one->id]);
        $this->assertDatabaseHas('invoice_line_items', ['id' => $line_item_two->id]);
    }

    public function test_deleting_a_nonexistent_order_returns_404(): void
    {
        $this->actingAs($this->admin, 'api')
            ->deleteJson('/api/admin/orders/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    public function test_super_admin_can_delete_an_order(): void
    {
        $order = $this->makeLinkBuildingOrder();

        $this->actingAs($this->super_admin, 'api')
            ->deleteJson("/api/admin/orders/{$order->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('link_building_orders', ['id' => $order->id]);
    }

    public function test_staff_cannot_delete_an_order(): void
    {
        $order = $this->makeLinkBuildingOrder();

        $this->actingAs($this->staff, 'api')
            ->deleteJson("/api/admin/orders/{$order->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('link_building_orders', ['id' => $order->id]);
    }

    public function test_client_cannot_delete_an_order(): void
    {
        $order = $this->makeLinkBuildingOrder();

        $this->actingAs($this->client, 'api')
            ->deleteJson("/api/admin/orders/{$order->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('link_building_orders', ['id' => $order->id]);
    }

    public function test_unauthenticated_request_cannot_delete_an_order(): void
    {
        $order = $this->makeLinkBuildingOrder();

        $this->deleteJson("/api/admin/orders/{$order->id}")
            ->assertStatus(401);

        $this->assertDatabaseHas('link_building_orders', ['id' => $order->id]);
    }
}
