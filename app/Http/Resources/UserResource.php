<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullName' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'emailVerifiedAt' => $this->email_verified_at?->toISOString(),
            'isVerifiedCreator' => $this->creator_verification_status === 'approved',
            'creatorVerificationStatus' => $this->creator_verification_status ?: 'unsubmitted',
            'creatorVerifiedAt' => $this->creator_verified_at?->toISOString(),
            'avatarUrl' => $this->avatar_url,
            'bio' => $this->bio,
            'isOnline' => $this->isActiveNow(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}