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

/**
 * Spreadsheet style copy and paste on the link building orders dashboard: a block
 * of new orders pasted into draft rows (batch-store), and pasted cells on saved
 * rows (batch-update-cells), including the Status and assignment columns.
 */
class LinkBuildingOrderBulkPasteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->client = User::factory()->create(['is_active' => true, 'company' => 'Acme Corp']);
        $this->client->assignRole('client');
    }

    private function newOrderRow(array $overrides = []): array
    {
        return array_merge([
            'link_type'    => 'DR 30+ External',
            'client'       => 'Acme Corp',
            'keyword'      => 'seo tools',
            'landing_page' => 'acme.com/seo',
            'exact_match'  => 'No',
        ], $overrides);
    }

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

    // ─── batch-store ──────────────────────────────────────────────────────────

    public function test_client_role_cannot_batch_store(): void
    {
        $this->actingAs($this->client, 'api')
            ->postJson('/api/admin/link-building-orders/batch-store', ['rows' => [$this->newOrderRow()]])
            ->assertStatus(403);
    }

    public function test_batch_store_creates_every_row_in_order_with_sequential_order_ids(): void
    {
        $this->adminPlacement(['order_id' => 'BL-500']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-store', [
                'rows' => [
                    $this->newOrderRow(['keyword' => 'first']),
                    $this->newOrderRow(['keyword' => 'second', 'exact_match' => 'Yes']),
                    $this->newOrderRow(['keyword' => 'third']),
                ],
            ])
            ->assertStatus(201);

        $this->assertSame(['first', 'second', 'third'], array_column($response->json('data'), 'keyword'));
        $this->assertSame(['BL-501', 'BL-502', 'BL-503'], array_column($response->json('data'), 'order_id'));
        $this->assertSame('Yes', $response->json('data.1.exact_match'));
        $this->assertSame('https://acme.com/seo', $response->json('data.0.landing_page'));
        $this->assertNotSame('', $response->json('data.0.request_date'));
        $this->assertNotSame('', $response->json('data.0.estimated_delivery_date'));
    }

    public function test_batch_store_ignores_order_ids_sent_by_the_client(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-store', [
                'rows' => [$this->newOrderRow(['order_id' => 'BL-99999'])],
            ])
            ->assertStatus(201);

        $this->assertSame('BL-1', $response->json('data.0.order_id'));
    }

    public function test_batch_store_creates_nothing_when_one_row_is_invalid(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-store', [
                'rows' => [
                    $this->newOrderRow(),
                    $this->newOrderRow(['keyword' => '']),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rows.1.keyword']);

        $this->assertSame(0, LinkBuildingOrderPlacement::count());
    }

    public function test_batch_store_rejects_an_empty_batch(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-store', ['rows' => []])
            ->assertStatus(422);
    }

    // ─── batch-update-cells ───────────────────────────────────────────────────

    public function test_batch_update_cells_pasting_a_status_emails_the_assigned_client(): void
    {
        Mail::fake();
        $placement = $this->adminPlacement(['user_id' => $this->client->id]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [['id' => $placement->id, 'fields' => ['status' => 'Live']]],
            ])
            ->assertOk();

        Mail::assertQueued(OrderStatusChangeMail::class, fn (OrderStatusChangeMail $mail) => $mail->status === 'completed');
    }

    public function test_batch_update_cells_pasting_the_same_status_sends_no_email(): void
    {
        Mail::fake();
        $placement = $this->adminPlacement(['user_id' => $this->client->id, 'status' => 'Live']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [['id' => $placement->id, 'fields' => ['status' => 'Live', 'keyword' => 'changed']]],
            ])
            ->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_batch_update_cells_pasting_live_on_a_purchased_placement_completes_its_order(): void
    {
        Mail::fake();

        $tier = DrTier::firstOrCreate(['id' => 'dr-40'], [
            'label'          => 'DR 40+',
            'traffic_range'  => '1k-5k',
            'word_count'     => 1000,
            'price_per_link' => 150,
        ]);
        $order = LinkBuildingOrder::create([
            'user_id'      => $this->client->id,
            'order_title'  => 'Pasted Order',
            'status'       => 'processing',
            'total_amount' => 150,
            'is_hidden'    => false,
        ]);
        $item = LinkBuildingOrderItem::create([
            'order_id'   => $order->id,
            'dr_tier_id' => $tier->id,
            'quantity'   => 1,
            'unit_price' => 150,
            'subtotal'   => 150,
        ]);
        $placement = LinkBuildingOrderPlacement::create([
            'order_item_id' => $item->id,
            'keyword'       => 'kw',
            'landing_page'  => 'https://example.com',
            'status'        => 'New Request',
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [['id' => $placement->id, 'fields' => ['status' => 'Live']]],
            ])
            ->assertOk();

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_batch_update_cells_pastes_and_clears_the_assignment_columns(): void
    {
        $placement = $this->adminPlacement(['assigned_admin_user_id' => $this->admin->id]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [[
                    'id'     => $placement->id,
                    'fields' => ['user_id' => (string) $this->client->id, 'assigned_admin_user_id' => ''],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $this->client->id)
            ->assertJsonPath('data.0.assigned_admin_user_id', null);
    }

    public function test_batch_update_cells_pastes_a_dr_value(): void
    {
        $placement = $this->adminPlacement();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [['id' => $placement->id, 'fields' => ['dr_lbs' => '54']]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.dr_lbs', '54');
    }

    public function test_batch_update_cells_rejects_more_rows_than_the_limit(): void
    {
        $updates = array_fill(0, 1001, ['id' => 'missing', 'fields' => ['keyword' => 'x']]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', ['updates' => $updates])
            ->assertStatus(422);
    }

    // ─── batch-update ─────────────────────────────────────────────────────────

    public function test_batch_update_stores_exact_match_display_text_as_a_boolean(): void
    {
        $placement = $this->adminPlacement(['exact_match' => false]);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update', [
                'row_ids' => [$placement->id],
                'updates' => ['exact_match' => 'Yes'],
            ])
            ->assertOk();

        $this->assertTrue($placement->fresh()->exact_match);
    }
}
