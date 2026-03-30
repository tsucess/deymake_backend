<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mediaUrl = $this->type === 'video'
            ? ($this->upload?->processed_url ?: $this->media_url ?: $this->upload?->url)
            : ($this->media_url ?: $this->upload?->url);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'caption' => $this->caption,
            'description' => $this->description,
            'location' => $this->location,
            'taggedUsers' => $this->tagged_users ?? [],
            'mediaUrl' => $mediaUrl,
            'originalMediaUrl' => $this->upload?->path ?: $this->media_url,
            'processedMediaUrl' => $this->upload?->processed_url,
            'thumbnailUrl' => $this->thumbnail_url,
            'processingStatus' => $this->upload?->processing_status ?? 'completed',
            'duration' => $this->upload?->duration,
            'width' => $this->upload?->width,
            'height' => $this->upload?->height,
            'views' => (int) $this->views_count,
            'likes' => (int) ($this->likes_count ?? 0),
            'dislikes' => (int) ($this->dislikes_count ?? 0),
            'saves' => (int) ($this->saves_count ?? 0),
            'shares' => (int) $this->shares_count,
            'commentsCount' => (int) ($this->comments_count ?? 0),
            'isLive' => (bool) $this->is_live,
            'isDraft' => (bool) $this->is_draft,
            'liveStartedAt' => $this->live_started_at?->toISOString(),
            'liveEndedAt' => $this->live_ended_at?->toISOString(),
            'author' => new ProfileResource($this->whenLoaded('user')),
            'creator' => new ProfileResource($this->whenLoaded('user')),
            'category' => $this->category ? new CategoryResource($this->category) : null,
            'currentUserState' => [
                'liked' => (bool) ($this->liked_by_current_user ?? false),
                'disliked' => (bool) ($this->disliked_by_current_user ?? false),
                'saved' => (bool) ($this->saved_by_current_user ?? false),
                'subscribed' => (bool) ($this->user?->subscribed_by_current_user ?? false),
            ],
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}