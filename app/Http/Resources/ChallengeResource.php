<?php

namespace App\Http\Resources;

use App\Models\Challenge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Challenge $challenge */
        $challenge = $this->resource;
        $viewer = auth('sanctum')->user() ?? $request->user();
        $submissionCount = (int) ($challenge->current_user_submissions_count ?? 0);

        return [
            'id' => $challenge->id,
            'title' => $challenge->title,
            'slug' => $challenge->slug,
            'summary' => $challenge->summary,
            'description' => $challenge->description,
            'bannerUrl' => $challenge->banner_url,
            'thumbnailUrl' => $challenge->thumbnail_url,
            'rules' => $challenge->rules ?? [],
            'prizes' => $challenge->prizes ?? [],
            'requirements' => $challenge->requirements ?? [],
            'judgingCriteria' => $challenge->judging_criteria ?? [],
            'submissionStartsAt' => $challenge->submission_starts_at?->toISOString(),
            'submissionEndsAt' => $challenge->submission_ends_at?->toISOString(),
            'publishedAt' => $challenge->published_at?->toISOString(),
            'closedAt' => $challenge->closed_at?->toISOString(),
            'status' => $challenge->status,
            'lifecycleStatus' => $challenge->lifecycleStatus(),
            'isFeatured' => (bool) $challenge->is_featured,
            'isOpen' => $challenge->isOpenForSubmissions(),
            'maxSubmissionsPerUser' => (int) $challenge->max_submissions_per_user,
            'submissionsCount' => (int) ($challenge->submissions_count ?? 0),
            'host' => $this->whenLoaded('host', fn () => new ProfileResource($challenge->host)),
            'currentUserState' => [
                'isHost' => $viewer ? $viewer->id === $challenge->host_id : false,
                'hasSubmitted' => $submissionCount > 0,
                'submissionCount' => $submissionCount,
                'canSubmit' => $viewer
                    ? $challenge->isOpenForSubmissions() && $submissionCount < (int) $challenge->max_submissions_per_user
                    : false,
            ],
            'createdAt' => $challenge->created_at?->toISOString(),
            'updatedAt' => $challenge->updated_at?->toISOString(),
        ];
    }
}