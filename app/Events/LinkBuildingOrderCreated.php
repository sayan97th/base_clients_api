<?php

namespace App\Events;

use App\Models\LinkBuildingOrderPlacement;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LinkBuildingOrderCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LinkBuildingOrderPlacement $placement,
        public ?string $created_by_session_id,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('link-building-orders')];
    }

    public function broadcastAs(): string
    {
        return 'LinkBuildingOrderCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'row'                   => $this->placement->toApiArray(),
            'created_by_session_id' => $this->created_by_session_id,
        ];
    }
}
