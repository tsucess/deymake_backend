<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CreatorSubscribersUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $creatorId,
        public int $subscriberCount,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('creators.'.$this->creatorId)];
    }

    public function broadcastAs(): string
    {
        return 'creator.subscribers.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'creatorId' => $this->creatorId,
            'subscriberCount' => $this->subscriberCount,
        ];
    }
}
