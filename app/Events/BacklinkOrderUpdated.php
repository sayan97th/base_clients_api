<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BacklinkOrderUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $row,
        public ?string $updated_by_session_id,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('backlink-orders')];
    }

    public function broadcastAs(): string
    {
        return 'BacklinkOrderUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'row'                   => $this->row,
            'updated_by_session_id' => $this->updated_by_session_id,
        ];
    }
}
