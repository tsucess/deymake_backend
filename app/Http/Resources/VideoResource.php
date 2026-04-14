<?php

namespace App\Http\Resources;

use App\Services\CloudinaryUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = auth('sanctum')->user() ?? $request->user();
        $originalMediaUrl = $this->upload?->path ?: $this->media_url;
        $liveLikes = (int) ($this->live_like_events_count ?? 0);
        $likes = (int) ($this->likes_count ?? 0) + $liveLikes;
        $currentViewers = (int) ($this->is_live ? ($this->current_viewers_count ?? 0) : 0);
        $mediaUrl = $this->type === 'video'
            ? ($this->upload?->processed_url ?: $this->media_url ?: $this->upload?->url)
            : ($this->media_url ?: $this->upload?->url);
        $streamUrl = null;

        if ($this->type === 'video' && is_string($originalMediaUrl) && $originalMediaUrl !== '') {
            $cloudinary = app(CloudinaryUploadService::class);

            if ($cloudinary->isManagedUrl($originalMediaUrl)) {
                $streamUrl = $cloudinary->streamUrlFor($originalMediaUrl);
            }
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'caption' => $this->caption,
            'description' => $this->description,
            'location' => $this->location,
            'taggedUsers' => $this->tagged_users ?? [],
            'mediaUrl' => $mediaUrl,
            'originalMediaUrl' => $originalMediaUrl,
            'processedMediaUrl' => $this->upload?->processed_url,
            'streamUrl' => $streamUrl,
            'thumbnailUrl' => $this->thumbnail_url,
            'processingStatus' => $this->upload?->processing_status ?? 'completed',
            'duration' => $this->upload?->duration,
            'width' => $this->upload?->width,
            'height' => $this->upload?->height,
            'views' => (int) $this->views_count,
            'likes' => $likes,
            'liveLikes' => $liveLikes,
            'currentViewers' => $currentViewers,
            'dislikes' => (int) ($this->dislikes_count ?? 0),
            'saves' => (int) ($this->saves_count ?? 0),
            'shares' => (int) $this->shares_count,
            'commentsCount' => (int) ($this->comments_count ?? 0),
            'liveComments' => (int) ($this->live_comments_count ?? 0),
            'isLive' => (bool) $this->is_live,
            'isDraft' => (bool) $this->is_draft,
            'liveStartedAt' => $this->live_started_at?->toISOString(),
            'liveEndedAt' => $this->live_ended_at?->toISOString(),
            'liveAnalytics' => [
                'currentViewers' => $currentViewers,
                'peakViewers' => (int) ($this->live_peak_viewers_count ?? 0),
                'liveLikes' => $liveLikes,
                'liveComments' => (int) ($this->live_comments_count ?? 0),
            ],
            'author' => new ProfileResource($this->whenLoaded('user')),
            'creator' => new ProfileResource($this->whenLoaded('user')),
            'category' => $this->category ? new CategoryResource($this->category) : null,
            'currentUserState' => [
                'liked' => (bool) ($this->liked_by_current_user ?? false),
                'disliked' => (bool) ($this->disliked_by_current_user ?? false),
                'saved' => (bool) ($this->saved_by_current_user ?? false),
                'subscribed' => (bool) ($this->user?->subscribed_by_current_user ?? false),
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