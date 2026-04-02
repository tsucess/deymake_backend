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
            'avatarUrl' => $this->avatar_url,
            'bio' => $this->bio,
            'isOnline' => (bool) ($this->is_online ?? false),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}