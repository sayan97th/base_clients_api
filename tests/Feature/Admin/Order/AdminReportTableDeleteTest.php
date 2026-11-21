<?php

namespace Tests\Feature\Admin\Order;

use App\Models\LinkBuildingOrder;
use App\Models\OrderReport;
use App\Models\OrderReportTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * Deleting a table from an order report (DeleteTableConfirmModal in the portal).
 * DELETE /api/admin/orders/{order}/report/tables/{table}
 */
class AdminReportTableDeleteTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAdminRoles();
        $this->admin = $this->makeUserWithRole('admin');
        $this->client = $this->makeUserWithRole('client');
    }

    private function makeOrder(): LinkBuildingOrder
    {
        return LinkBuildingOrder::create([
            'user_id'                  => $this->client->id,
            'order_title'              => 'Test Link Building Order',
            'order_notes'              => null,
            'subtotal_before_discount' => 100.0,
            'total_amount'             => 100.0,
            'status'                   => 'processing',
            'payment_intent_id'        => 'pi_test_' . uniqid(),
        ]);
    }

    private function makeTable(LinkBuildingOrder $order, string $title = 'Placements'): OrderReportTable
    {
        $report = OrderReport::firstOrCreate(['order_id' => $order->id]);

        return OrderReportTable::create([
            'report_id'   => $report->id,
            'title'       => $title,
            'description' => 'Live links for this month.',
        ]);
    }

    private function uri(LinkBuildingOrder $order, OrderReportTable $table): string
    {
        return "/api/admin/orders/{$order->id}/report/tables/{$table->id}";
    }

    public function test_admin_can_delete_a_report_table(): void
    {
        $order = $this->makeOrder();
        $table = $this->makeTable($order);

        $this->deleteAs($this->admin, $this->uri($order, $table))->assertNoContent();

        $this->assertModelMissing($table);
    }

    public function test_other_tables_of_the_report_are_kept(): void
    {
        $order = $this->makeOrder();
        $deleted = $this->makeTable($order, 'January');
        $kept = $this->makeTable($order, 'February');

        $this->deleteAs($this->admin, $this->uri($order, $deleted))->assertNoContent();

        $this->assertModelMissing($deleted);
        $this->assertModelExists($kept);
    }

    public function test_table_from_another_order_cannot_be_deleted_through_this_order(): void
    {
        $order = $this->makeOrder();
        $other_order = $this->makeOrder();
        $other_table = $this->makeTable($other_order);

        $this->deleteAs($this->admin, $this->uri($order, $other_table))->assertNotFound();

        $this->assertModelExists($other_table);
    }

    public function test_client_cannot_delete_a_report_table(): void
    {
        $order = $this->makeOrder();
        $table = $this->makeTable($order);

        $this->deleteAs($this->client, $this->uri($order, $table))->assertForbidden();

        $this->assertModelExists($table);
    }

    public function test_guest_cannot_delete_a_report_table(): void
    {
        $order = $this->makeOrder();
        $table = $this->makeTable($order);

        $this->deleteJson($this->uri($order, $table))->assertUnauthorized();

        $this->assertModelExists($table);
    }
}
