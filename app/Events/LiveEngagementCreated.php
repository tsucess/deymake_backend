<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveEngagementCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $videoId,
        public array $engagement,
        public ?array $comment = null,
        public array $analytics = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('live.videos.'.$this->videoId)];
    }

    public function broadcastAs(): string
    {
        return 'live.engagement.created';
    }

    public function broadcastWith(): array
    {
        return array_filter([
            'videoId' => $this->videoId,
            'engagement' => $this->engagement,
            'comment' => $this->comment,
            'analytics' => $this->analytics,
        ], static fn ($value) => $value !== null);
    }
}