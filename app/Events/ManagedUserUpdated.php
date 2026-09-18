<?php

namespace App\Events;

use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ManagedUserUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.users')];
    }

    public function broadcastAs(): string
    {
        return 'admin.user.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'user' => (new AdminUserResource($this->user))->resolve(),
        ];
    }
}
