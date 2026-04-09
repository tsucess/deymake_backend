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
            'username' => $this->username,
            'bio' => $this->bio,
            'avatarUrl' => $this->avatar_url,
            'isOnline' => $this->isActiveNow(),
            'subscriberCount' => (int) ($this->subscribers_count ?? 0),
            'isDeveloper' => (int) ($this->tokens_count ?? 0) > 0 || (int) ($this->webhooks_count ?? 0) > 0,
            'hasActivePlans' => (int) ($this->active_creator_plans_count ?? 0) > 0,
            'activePlansCount' => (int) ($this->active_creator_plans_count ?? 0),
            'currentUserState' => [
                'subscribed' => (bool) ($this->subscribed_by_current_user ?? false),
            ],
            'createdAt' => $this->created_at?->toISOString(),
            'email' => $request->user()?->is($this->resource) ? $this->email : null,
        ];
    }
}