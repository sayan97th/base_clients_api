<?php

namespace Tests\Unit\LinkBuilding;

use App\Events\LinkBuildingOrderSheetFormulasChanged;
use Illuminate\Broadcasting\PresenceChannel;
use PHPUnit\Framework\TestCase;

/**
 * The event every open Link Building Orders table listens to, to reload the
 * team's formula columns and totals row after someone changes them.
 */
class LinkBuildingOrderSheetFormulasChangedTest extends TestCase
{
    public function test_it_is_broadcast_on_the_link_building_orders_presence_channel(): void
    {
        $channels = (new LinkBuildingOrderSheetFormulasChanged('session-1'))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PresenceChannel::class, $channels[0]);
        $this->assertSame('presence-link-building-orders', $channels[0]->name);
    }

    public function test_it_names_the_event_and_the_session_that_made_the_change(): void
    {
        $event = new LinkBuildingOrderSheetFormulasChanged('session-1');

        $this->assertSame('LinkBuildingOrderSheetFormulasChanged', $event->broadcastAs());
        $this->assertSame(['changed_by_session_id' => 'session-1'], $event->broadcastWith());
        $this->assertSame(['changed_by_session_id' => null], (new LinkBuildingOrderSheetFormulasChanged(null))->broadcastWith());
    }
}
