<?php

namespace App\Http\Resources;

use App\Models\Comment;
use App\Models\ContentModerationCase;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentModerationCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ContentModerationCase $moderationCase */
        $moderationCase = $this->resource;

        return [
            'id' => $moderationCase->id,
            'contentType' => $moderationCase->content_type,
            'source' => $moderationCase->source,
            'status' => $moderationCase->status,
            'aiScore' => (int) $moderationCase->ai_score,
            'aiRiskLevel' => $moderationCase->ai_risk_level,
            'aiFlags' => $moderationCase->ai_flags ?? [],
            'aiSummary' => $moderationCase->ai_summary,
            'reportCount' => (int) $moderationCase->report_count,
            'lastReportedAt' => $moderationCase->last_reported_at?->toISOString(),
            'reviewNotes' => $moderationCase->review_notes,
            'actionReason' => $moderationCase->action_reason,
            'reviewedAt' => $moderationCase->reviewed_at?->toISOString(),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $moderationCase->reviewer ? new UserResource($moderationCase->reviewer) : null),
            'subject' => $this->subjectPayload($moderationCase),
            'createdAt' => $moderationCase->created_at?->toISOString(),
            'updatedAt' => $moderationCase->updated_at?->toISOString(),
        ];
    }

    private function subjectPayload(ContentModerationCase $moderationCase): ?array
    {
        $subject = $moderationCase->moderatable;

        if ($subject instanceof Video) {
            return [
                'id' => $subject->id,
                'type' => 'video',
                'title' => $subject->title,
                'caption' => $subject->caption,
                'description' => $subject->description,
                'thumbnailUrl' => $subject->thumbnail_url,
                'ownerId' => $subject->user_id,
                'moderationStatus' => $subject->moderation_status,
                'createdAt' => $subject->created_at?->toISOString(),
            ];
        }

        if ($subject instanceof Comment) {
            return [
                'id' => $subject->id,
                'type' => 'comment',
                'body' => $subject->body,
                'parentId' => $subject->parent_id,
                'videoId' => $subject->video_id,
                'ownerId' => $subject->user_id,
                'moderationStatus' => $subject->moderation_status,
                'createdAt' => $subject->created_at?->toISOString(),
            ];
        }

        return null;
    }
}