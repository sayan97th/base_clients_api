<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GenericTestEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $channel,
        public string $event,
        public array $payload,
        public string $channel_type = 'public',
    ) {}

    public function broadcastOn(): array
    {
        if ($this->channel_type === 'private') {
            return [new PrivateChannel($this->channel)];
        }

        return [new Channel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return ltrim($this->event, '.');
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
