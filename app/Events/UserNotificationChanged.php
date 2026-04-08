<?php

namespace App\Events;

use App\Http\Resources\UserNotificationResource;
use App\Models\UserNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserNotificationChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public string $action,
        public ?UserNotification $notification = null,
        public ?int $notificationId = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.'.$this->action;
    }

    public function broadcastWith(): array
    {
        if ($this->action === 'deleted') {
            return [
                'notificationId' => $this->notificationId,
            ];
        }

        return [
            'notification' => (new UserNotificationResource($this->notification))->resolve(),
        ];
    }
}