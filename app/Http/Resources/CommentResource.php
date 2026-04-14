<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        return [
            'id' => $this->id,
            'body' => $this->body,
            'text' => $this->body,
            'parentId' => $this->parent_id,
            'likes' => (int) ($this->likes_count ?? 0),
            'dislikes' => (int) ($this->dislikes_count ?? 0),
            'repliesCount' => (int) ($this->replies_count ?? 0),
            'user' => new ProfileResource($this->whenLoaded('user')),
            'currentUserState' => [
                'liked' => (bool) ($this->liked_by_current_user ?? false),
                'disliked' => (bool) ($this->disliked_by_current_user ?? false),
            ],
            'moderation' => $this->when(
                $viewer && ($viewer->id === $this->user_id || $viewer->isAdmin()),
                fn () => [
                    'status' => $this->moderation_status,
                    'notes' => $this->moderation_notes,
                    'moderatedAt' => $this->moderated_at?->toISOString(),
                ]
            ),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}