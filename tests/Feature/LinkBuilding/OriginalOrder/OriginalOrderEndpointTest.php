<?php

namespace Tests\Feature\LinkBuilding\OriginalOrder;

use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderSnapshot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\LinkBuildingOrderSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * The admin "Original Order" view: the read only copy of the order next to the
 * values its placements hold now on the Link Building Orders table.
 */
class OriginalOrderEndpointTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    private User $client;

    private LinkBuildingOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
        $this->client = $this->makeUser('client', ['first_name' => 'Weave', 'last_name' => 'Buyer', 'company' => 'Weave']);
        $this->order  = $this->clientOrder($this->client, [
            ['order_id' => 'BL-25687', 'client' => 'Weave', 'keyword' => 'other fatigue icd 10', 'landing_page' => 'https://www.getweave.com/fatigue-icd-10/', 'exact_match' => true],
            ['order_id' => 'BL-25689', 'client' => 'Weave', 'keyword' => 'dental front office training', 'landing_page' => 'https://www.getweave.com/x/'],
        ]);
        app(LinkBuildingOrderSnapshotService::class)->capturePlaced($this->order, LinkBuildingOrderSnapshot::PAYMENT_CREDIT_CARD);
    }

    private function original()
    {
        return $this->actingAs($this->admin, 'api')->getJson("/api/admin/orders/{$this->order->id}/original")->assertOk();
    }

    public function test_the_original_order_is_shown_next_to_what_the_placements_hold_now(): void
    {
        [$first, $second] = $this->order->items->first()->placements->sortBy('row_index')->values()->all();

        // The reported incident: the row is pasted over with another client's order.
        $this->pasteOver([$first->id => ['order_id' => 'BL-25730', 'client' => 'Mather', 'keyword' => 'senior living']]);
        $this->actingAs($this->admin, 'api')->deleteJson("/api/admin/link-building-orders/{$second->id}")->assertOk();

        $response = $this->original()
            ->assertJsonPath('data.order.id', $this->order->id)
            ->assertJsonPath('data.order.product_type', 'link_building')
            ->assertJsonPath('data.order.user.company', 'Weave')
            ->assertJsonPath('data.snapshots.0.type', 'placed')
            ->assertJsonPath('data.snapshots.0.payment_method', 'Credit Card')
            ->assertJsonPath('data.snapshots.0.is_backfilled', false)
            ->assertJsonPath('data.snapshots.0.payload.items.0.placements.0.order_id', 'BL-25687')
            ->assertJsonPath('data.snapshots.0.payload.items.0.placements.0.client', 'Weave')
            ->assertJsonPath('data.snapshots.0.payload.items.0.placements.0.keyword', 'other fatigue icd 10')
            ->assertJsonPath("data.current_placements.{$first->id}.client", 'Mather')
            ->assertJsonPath("data.current_placements.{$first->id}.keyword", 'senior living');

        // Order IDs are only edited one row at a time, so the paste kept it.
        $this->assertSame('BL-25687', $response->json("data.current_placements.{$first->id}.order_id"));
        $this->assertArrayNotHasKey($second->id, $response->json('data.current_placements'), 'A deleted placement is missing from the current values.');
    }

    public function test_the_copies_are_listed_oldest_first_with_who_took_them(): void
    {
        $this->travel(1)->hour();
        $this->actingAs($this->client, 'api');
        app(LinkBuildingOrderSnapshotService::class)->captureDetailsSubmitted($this->order);

        $this->original()
            ->assertJsonCount(2, 'data.snapshots')
            ->assertJsonPath('data.snapshots.0.type', 'placed')
            ->assertJsonPath('data.snapshots.1.type', 'details_submitted')
            ->assertJsonPath('data.snapshots.1.captured_by.name', 'Weave Buyer');
    }

    public function test_the_current_invoice_is_shown_with_the_copy(): void
    {
        $invoice = app(InvoiceService::class)->createForLinkBuildingOrder($this->client, $this->order, 'Credit Card');

        $this->original()
            ->assertJsonPath('data.invoice.invoice_number', $invoice->invoice_number)
            ->assertJsonPath('data.invoice.status', 'paid')
            ->assertJsonPath('data.invoice.payment_method', 'Credit Card');
    }

    public function test_an_order_without_copies_answers_with_an_empty_list(): void
    {
        $order = $this->clientOrder($this->client);

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/orders/{$order->id}/original")
            ->assertOk()
            ->assertJsonCount(0, 'data.snapshots')
            ->assertJsonPath('data.invoice', null);
    }

    public function test_only_link_building_orders_have_an_original_view(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/orders/' . Str::uuid() . '/original')
            ->assertNotFound();
    }

    // ─── Read only ────────────────────────────────────────────────────────────

    public function test_a_copy_cannot_be_changed(): void
    {
        $this->expectException(LogicException::class);

        $this->order->snapshots()->sole()->update(['payment_method' => 'Edited']);
    }

    public function test_a_copy_cannot_be_deleted_on_its_own(): void
    {
        $this->expectException(LogicException::class);

        $this->order->snapshots()->sole()->delete();
    }

    public function test_the_copies_go_away_with_their_order(): void
    {
        $this->actingAs($this->makeUser('super_admin'), 'api')
            ->deleteJson("/api/admin/orders/{$this->order->id}")
            ->assertNoContent();

        $this->assertSame(0, LinkBuildingOrderSnapshot::count());
    }

    // ─── Access ───────────────────────────────────────────────────────────────

    public static function roleProvider(): array
    {
        return [
            'super admin' => ['super_admin', 200],
            'admin'       => ['admin', 200],
            'staff'       => ['staff', 200],
            'client'      => ['client', 403],
        ];
    }

    #[DataProvider('roleProvider')]
    public function test_who_can_open_the_original_view(string $role, int $status): void
    {
        $this->actingAs($this->makeUser($role), 'api')
            ->getJson("/api/admin/orders/{$this->order->id}/original")
            ->assertStatus($status);
    }

    public function test_guests_cannot_open_the_original_view(): void
    {
        $this->getJson("/api/admin/orders/{$this->order->id}/original")->assertUnauthorized();
    }
}
