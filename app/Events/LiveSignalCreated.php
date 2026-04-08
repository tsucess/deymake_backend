<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveSignalCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $videoId,
        public int $recipientId,
        public array $signal,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('live.videos.'.$this->videoId.'.users.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'live.signal.created';
    }

    public function broadcastWith(): array
    {
        return [
            'videoId' => $this->videoId,
            'signal' => $this->signal,
        ];
    }
}