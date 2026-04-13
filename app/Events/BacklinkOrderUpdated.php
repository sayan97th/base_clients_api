<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
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
        return [new Channel('backlink-orders')];
    }

    public function broadcastAs(): string
    {
        return 'row_updated';
    }

    public function broadcastWith(): array
    {
        return [
            'type'                  => 'row_updated',
            'row'                   => $this->row,
            'updated_by_session_id' => $this->updated_by_session_id,
        ];
    }
}
