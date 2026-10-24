<?php

namespace App\Events;

use App\Models\LinkBuildingOrderPlacement;
use App\Services\LinkBuildingDomainUsageService;
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
        // Annotated here, not by the caller, since the row may be restored from the
        // serialized event and the flag must reach every table that shows it.
        app(LinkBuildingDomainUsageService::class)->annotate([$this->placement]);

        return [
            'row'                   => $this->placement->toApiArray(),
            'updated_by_session_id' => $this->updated_by_session_id,
        ];
    }
}
