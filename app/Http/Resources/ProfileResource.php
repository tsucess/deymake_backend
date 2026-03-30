<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullName' => $this->name,
            'bio' => $this->bio,
            'avatarUrl' => $this->avatar_url,
            'isOnline' => (bool) ($this->is_online ?? false),
            'subscriberCount' => (int) ($this->subscribers_count ?? 0),
            'createdAt' => $this->created_at?->toISOString(),
            'email' => $request->user()?->is($this->resource) ? $this->email : null,
        ];
    }
}