<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'caption' => $this->caption,
            'description' => $this->description,
            'mediaUrl' => $this->media_url,
            'thumbnailUrl' => $this->thumbnail_url,
            'externalUrl' => $this->external_url,
            'metadata' => $this->metadata ?? [],
            'status' => $this->status,
            'submittedAt' => $this->submitted_at?->toISOString(),
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'withdrawnAt' => $this->withdrawn_at?->toISOString(),
            'user' => $this->whenLoaded('user', fn () => new ProfileResource($this->user)),
            'challenge' => $this->whenLoaded('challenge', fn () => [
                'id' => $this->challenge?->id,
                'title' => $this->challenge?->title,
                'slug' => $this->challenge?->slug,
            ]),
            'video' => $this->whenLoaded('video', fn () => $this->video ? [
                'id' => $this->video->id,
                'title' => $this->video->title,
                'thumbnailUrl' => $this->video->thumbnail_url,
                'mediaUrl' => $this->video->media_url,
            ] : null),
            'currentUserState' => [
                'isOwner' => $viewer?->id === $this->user_id,
                'canWithdraw' => $viewer?->id === $this->user_id && $this->status !== 'withdrawn',
            ],
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}