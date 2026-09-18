<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserAccountUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->user->id)];
    }

    public function broadcastAs(): string
    {
        return 'user.account.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'isVerifiedCreator' => $this->user->creator_verification_status === 'approved',
                'creatorVerificationStatus' => $this->user->creator_verification_status ?: 'unsubmitted',
                'creatorVerifiedAt' => $this->user->creator_verified_at?->toISOString(),
                'accountStatus' => $this->user->accountStatus(),
                'isSuspended' => $this->user->isSuspended(),
                'isBanned' => $this->user->isBanned(),
                'isAdmin' => (bool) $this->user->is_admin,
            ],
        ];
    }
}
