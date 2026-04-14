<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'details' => $this->details,
            'status' => $this->status,
            'resolutionNotes' => $this->resolution_notes,
            'createdAt' => $this->created_at?->toISOString(),
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'reporter' => $this->whenLoaded('user', fn () => $this->user ? new UserResource($this->user) : null),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? new UserResource($this->reviewer) : null),
            'video' => $this->whenLoaded('video', function () {
                if (! $this->video) {
                    return null;
                }

                return [
                    'id' => $this->video->id,
                    'title' => $this->video->title,
                    'caption' => $this->video->caption,
                    'thumbnailUrl' => $this->video->thumbnail_url,
                    'mediaUrl' => $this->video->media_url,
                    'isDraft' => (bool) $this->video->is_draft,
                    'isLive' => (bool) $this->video->is_live,
                    'author' => $this->video->relationLoaded('user') && $this->video->user
                        ? new ProfileResource($this->video->user)
                        : null,
                ];
            }),
        ];
    }
}