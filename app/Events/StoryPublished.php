<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StoryPublished implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $recipientId,
        public int $storyId,
        public int $authorId,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('story-feed.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'story.created';
    }

    public function broadcastWith(): array
    {
        return [
            'storyId' => $this->storyId,
            'authorId' => $this->authorId,
        ];
    }
}