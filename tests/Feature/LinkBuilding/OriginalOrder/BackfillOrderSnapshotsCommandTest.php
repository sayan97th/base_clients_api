<?php

namespace Tests\Feature\LinkBuilding\OriginalOrder;

use App\Models\LinkBuildingOrderSnapshot;
use App\Services\InvoiceService;
use App\Services\LinkBuildingOrderSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * `link-building:backfill-order-snapshots` gives orders placed before copies were
 * kept at checkout an "Original Order" copy, flagged as backfilled.
 */
class BackfillOrderSnapshotsCommandTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
    }

    public function test_older_orders_get_a_flagged_copy_of_the_data_they_have_now(): void
    {
        $client = $this->makeUser('client');
        $order  = $this->clientOrder($client, [['order_id' => 'BL-25687', 'keyword' => 'legacy keyword']]);
        app(InvoiceService::class)->createForLinkBuildingOrder($client, $order, 'Account Balance');

        $this->artisan('link-building:backfill-order-snapshots')
            ->expectsOutputToContain('Found 1 order(s) without an original copy.')
            ->assertSuccessful();

        $snapshot = $order->snapshots()->sole();
        $this->assertTrue($snapshot->is_backfilled);
        $this->assertSame(LinkBuildingOrderSnapshot::TYPE_PLACED, $snapshot->type);
        $this->assertSame('Account Balance', $snapshot->payment_method);
        $this->assertNull($snapshot->captured_by_id);
        $this->assertSame('BL-25687', $snapshot->payload['items'][0]['placements'][0]['order_id']);
        $this->assertSame('legacy keyword', $snapshot->payload['items'][0]['placements'][0]['keyword']);
    }

    public function test_it_can_run_more_than_once(): void
    {
        $order = $this->clientOrder($this->makeUser('client'));

        $this->artisan('link-building:backfill-order-snapshots')->assertSuccessful();
        $this->artisan('link-building:backfill-order-snapshots')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertSuccessful();

        $this->assertSame(1, $order->snapshots()->count());
    }

    public function test_orders_with_a_checkout_copy_are_left_alone(): void
    {
        $order = $this->clientOrder($this->makeUser('client'));
        app(LinkBuildingOrderSnapshotService::class)->capturePlaced($order, LinkBuildingOrderSnapshot::PAYMENT_CREDIT_CARD);

        $this->artisan('link-building:backfill-order-snapshots')->assertSuccessful();

        $this->assertFalse($order->snapshots()->sole()->is_backfilled);
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->clientOrder($this->makeUser('client'));

        $this->artisan('link-building:backfill-order-snapshots', ['--dry-run' => true])
            ->expectsOutputToContain('Dry-run mode')
            ->assertSuccessful();

        $this->assertSame(0, LinkBuildingOrderSnapshot::count());
    }
}
