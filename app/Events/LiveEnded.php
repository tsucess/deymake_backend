<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveEnded implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $videoId,
        public string $endedAt,
        public string $liveStartedAt,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('live.videos.'.$this->videoId),
            new PrivateChannel('admin.live'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'live.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'videoId' => $this->videoId,
            'status' => 'ended',
            'endedAt' => $this->endedAt,
            'liveStartedAt' => $this->liveStartedAt,
        ];
    }
}
