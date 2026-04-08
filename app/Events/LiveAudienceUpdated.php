<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveAudienceUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $videoId,
        public array $audience,
        public array $analytics,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('live.videos.'.$this->videoId.'.creator')];
    }

    public function broadcastAs(): string
    {
        return 'live.audience.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'videoId' => $this->videoId,
            'audience' => $this->audience,
            'analytics' => $this->analytics,
        ];
    }
}