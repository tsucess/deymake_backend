<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'mediaUrl' => $this->media_url,
            'thumbnailUrl' => $this->thumbnail_url,
            'caption' => $this->caption,
            'views' => (int) $this->views_count,
            'expiresAt' => $this->expires_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'author' => new ProfileResource($this->whenLoaded('user')),
            'isOwner' => (bool) ($viewer && $viewer->id === $this->user_id),
            'currentUserState' => [
                'seen' => (bool) ($this->seen_by_current_user ?? false),
            ],
        ];
    }
}
