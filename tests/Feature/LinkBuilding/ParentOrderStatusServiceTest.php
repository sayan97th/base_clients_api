<?php

namespace Tests\Feature\LinkBuilding;

use App\Mail\OrderStatusChangeMail;
use App\Models\LinkBuildingOrderPlacement;
use App\Services\LinkBuildingParentOrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * The client's order follows the statuses of its placements on the Link Building
 * Orders table: completed once every placement is Live, processing otherwise.
 * Dashboard edits email the client about it, history restores do not.
 */
class ParentOrderStatusServiceTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
    }

    public function test_the_order_completes_when_every_placement_is_live_and_the_client_is_emailed(): void
    {
        $client = $this->makeUser('client');
        $order  = $this->clientOrder($client, [['status' => 'Live'], ['status' => 'Live']]);

        app(LinkBuildingParentOrderStatusService::class)->sync($order->items->first()->placements->first());

        $this->assertSame('completed', $order->fresh()->status);
        Mail::assertQueued(OrderStatusChangeMail::class, fn ($mail) => $mail->hasTo($client->email));
    }

    public function test_the_order_is_processing_while_any_placement_is_not_live(): void
    {
        $order = $this->clientOrder($this->makeUser('client'), [['status' => 'Live'], ['status' => 'In Progress']], ['status' => 'completed']);

        app(LinkBuildingParentOrderStatusService::class)->sync($order->items->first()->placements->first());

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_the_client_is_not_emailed_when_asked_not_to(): void
    {
        $order = $this->clientOrder($this->makeUser('client'), [['status' => 'Live']]);

        app(LinkBuildingParentOrderStatusService::class)->sync($order->items->first()->placements->first(), notify_client: false);

        $this->assertSame('completed', $order->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_nothing_happens_when_the_status_is_already_right_or_there_is_no_order(): void
    {
        $order = $this->clientOrder($this->makeUser('client'), [['status' => 'In Progress']]);

        app(LinkBuildingParentOrderStatusService::class)->sync($order->items->first()->placements->first());
        app(LinkBuildingParentOrderStatusService::class)->sync(LinkBuildingOrderPlacement::create(['order_id' => 'BL-1', 'status' => 'Live']));

        $this->assertSame('processing', $order->fresh()->status);
        Mail::assertNothingQueued();
    }
}
