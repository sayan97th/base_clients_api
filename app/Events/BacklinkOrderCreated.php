<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BacklinkOrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $row,
        public ?string $created_by_session_id,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('backlink-orders')];
    }

    public function broadcastAs(): string
    {
        return 'row_created';
    }

    public function broadcastWith(): array
    {
        return [
            'type'                  => 'row_created',
            'row'                   => $this->row,
            'created_by_session_id' => $this->created_by_session_id,
        ];
    }
}
