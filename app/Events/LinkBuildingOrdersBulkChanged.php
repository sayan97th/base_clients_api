<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Many rows of the admin "Link Building Orders" table changed at once, such as a
 * large history restore. Sent instead of one LinkBuildingOrderUpdated per row:
 * every open table reloads its rows once.
 */
class LinkBuildingOrdersBulkChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public ?string $changed_by_session_id,
        public int $row_count,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('link-building-orders')];
    }

    public function broadcastAs(): string
    {
        return 'LinkBuildingOrdersBulkChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'changed_by_session_id' => $this->changed_by_session_id,
            'row_count'             => $this->row_count,
        ];
    }
}
