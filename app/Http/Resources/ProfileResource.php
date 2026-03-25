<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

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
            'subscriberCount' => (int) DB::table('subscriptions')->where('creator_id', $this->id)->count(),
            'createdAt' => $this->created_at?->toISOString(),
            'email' => $request->user()?->is($this->resource) ? $this->email : null,
        ];
    }
}