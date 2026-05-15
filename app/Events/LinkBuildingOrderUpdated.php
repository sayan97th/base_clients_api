<?php

namespace App\Events;

use App\Models\LinkBuildingOrderPlacement;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LinkBuildingOrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LinkBuildingOrderPlacement $placement,
        public ?string $updated_by_session_id,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('link-building-orders')];
    }

    public function broadcastAs(): string
    {
        return 'LinkBuildingOrderUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'row'                   => $this->placement->toApiArray(),
            'updated_by_session_id' => $this->updated_by_session_id,
        ];
    }
}
