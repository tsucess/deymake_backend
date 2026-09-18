<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CreatorVerificationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('creators')];
    }

    public function broadcastAs(): string
    {
        return 'creator.verification.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->user->id,
            'isVerifiedCreator' => $this->user->creator_verification_status === 'approved',
            'creatorVerificationStatus' => $this->user->creator_verification_status ?: 'unsubmitted',
        ];
    }
}
