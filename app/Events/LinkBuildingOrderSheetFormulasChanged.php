<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A calculated column or a totals row formula of the admin "Link Building Orders"
 * spreadsheet was added, changed or removed. Every open table reloads the
 * formulas, so the whole team keeps seeing the same columns.
 */
class LinkBuildingOrderSheetFormulasChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public ?string $changed_by_session_id,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('link-building-orders')];
    }

    public function broadcastAs(): string
    {
        return 'LinkBuildingOrderSheetFormulasChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'changed_by_session_id' => $this->changed_by_session_id,
        ];
    }
}
