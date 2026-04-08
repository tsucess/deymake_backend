<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LivePresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $videoId,
        public array $analytics,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('live.videos.'.$this->videoId)];
    }

    public function broadcastAs(): string
    {
        return 'live.presence.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'videoId' => $this->videoId,
            'analytics' => $this->analytics,
        ];
    }
}