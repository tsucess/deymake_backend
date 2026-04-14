<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollaborationInviteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'message' => $this->message,
            'conversationId' => $this->conversation_id,
            'deliverablesCount' => $this->whenCounted('deliverables'),
            'canRespond' => $request->user()?->id === $this->invitee_id && $this->status === 'pending',
            'canCancel' => $request->user()?->id === $this->inviter_id && $this->status === 'pending',
            'isExpired' => $this->status === 'expired' || ($this->status === 'pending' && $this->expires_at?->isPast()),
            'expiresAt' => $this->expires_at?->toISOString(),
            'respondedAt' => $this->responded_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            'inviter' => $this->whenLoaded('inviter', fn () => $this->inviter ? new ProfileResource($this->inviter) : null),
            'invitee' => $this->whenLoaded('invitee', fn () => $this->invitee ? new ProfileResource($this->invitee) : null),
            'sourceVideo' => $this->whenLoaded('sourceVideo', function () {
                if (! $this->sourceVideo) {
                    return null;
                }

                return [
                    'id' => $this->sourceVideo->id,
                    'title' => $this->sourceVideo->title,
                    'caption' => $this->sourceVideo->caption,
                    'thumbnailUrl' => $this->sourceVideo->thumbnail_url,
                    'author' => $this->sourceVideo->relationLoaded('user') && $this->sourceVideo->user
                        ? new ProfileResource($this->sourceVideo->user)
                        : null,
                ];
            }),
        ];
    }
}