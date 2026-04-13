<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BacklinkOrderDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $row_id,
        public ?string $deleted_by_session_id,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('backlink-orders')];
    }

    public function broadcastAs(): string
    {
        return 'row_deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'type'                  => 'row_deleted',
            'row_id'                => $this->row_id,
            'deleted_by_session_id' => $this->deleted_by_session_id,
        ];
    }
}
