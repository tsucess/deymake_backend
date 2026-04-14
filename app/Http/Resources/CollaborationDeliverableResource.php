<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollaborationDeliverableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $userId = $request->user()?->id;
        $invite = $this->relationLoaded('collaborationInvite') ? $this->collaborationInvite : null;

        return [
            'id' => $this->id,
            'inviteId' => $this->collaboration_invite_id,
            'title' => $this->title,
            'brief' => $this->brief,
            'feedback' => $this->feedback,
            'status' => $this->status,
            'submittedAt' => $this->submitted_at?->toISOString(),
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            'canEdit' => $userId === $this->created_by && in_array($this->status, ['drafting', 'changes_requested'], true),
            'canReview' => $invite && $userId && $userId !== $this->created_by && in_array($this->status, ['submitted'], true),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? new ProfileResource($this->creator) : null),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? new ProfileResource($this->reviewer) : null),
            'draftVideo' => $this->whenLoaded('draftVideo', function () {
                if (! $this->draftVideo) {
                    return null;
                }

                return [
                    'id' => $this->draftVideo->id,
                    'title' => $this->draftVideo->title,
                    'caption' => $this->draftVideo->caption,
                    'thumbnailUrl' => $this->draftVideo->thumbnail_url,
                    'isDraft' => (bool) $this->draftVideo->is_draft,
                    'ownerId' => $this->draftVideo->user_id,
                ];
            }),
        ];
    }
}