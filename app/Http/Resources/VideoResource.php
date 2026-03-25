<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class VideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'caption' => $this->caption,
            'description' => $this->description,
            'location' => $this->location,
            'taggedUsers' => $this->tagged_users ?? [],
            'mediaUrl' => $this->media_url ?: $this->upload?->url,
            'thumbnailUrl' => $this->thumbnail_url,
            'views' => (int) $this->views_count,
            'likes' => (int) DB::table('video_interactions')->where('video_id', $this->id)->where('type', 'like')->count(),
            'dislikes' => (int) DB::table('video_interactions')->where('video_id', $this->id)->where('type', 'dislike')->count(),
            'saves' => (int) DB::table('video_interactions')->where('video_id', $this->id)->where('type', 'save')->count(),
            'shares' => (int) $this->shares_count,
            'commentsCount' => (int) DB::table('comments')->where('video_id', $this->id)->count(),
            'isLive' => (bool) $this->is_live,
            'isDraft' => (bool) $this->is_draft,
            'author' => new ProfileResource($this->whenLoaded('user')),
            'creator' => new ProfileResource($this->whenLoaded('user')),
            'category' => $this->category ? new CategoryResource($this->category) : null,
            'currentUserState' => [
                'liked' => $user ? DB::table('video_interactions')->where('video_id', $this->id)->where('user_id', $user->id)->where('type', 'like')->exists() : false,
                'disliked' => $user ? DB::table('video_interactions')->where('video_id', $this->id)->where('user_id', $user->id)->where('type', 'dislike')->exists() : false,
                'saved' => $user ? DB::table('video_interactions')->where('video_id', $this->id)->where('user_id', $user->id)->where('type', 'save')->exists() : false,
                'subscribed' => $user ? DB::table('subscriptions')->where('creator_id', $this->user_id)->where('user_id', $user->id)->exists() : false,
            ],
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}