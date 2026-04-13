<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use App\Models\BacklinkOrder;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BacklinkOrderCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public BacklinkOrder $order,
        public ?string $created_by_session_id,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('backlink-orders')];
    }

    public function broadcastAs(): string
    {
        return 'BacklinkOrderCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'row'                   => $this->order->toApiArray(),
            'created_by_session_id' => $this->created_by_session_id,
        ];
    }
}
