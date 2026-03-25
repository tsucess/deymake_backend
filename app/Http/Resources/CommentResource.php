<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'body' => $this->body,
            'text' => $this->body,
            'parentId' => $this->parent_id,
            'likes' => (int) DB::table('comment_interactions')->where('comment_id', $this->id)->where('type', 'like')->count(),
            'dislikes' => (int) DB::table('comment_interactions')->where('comment_id', $this->id)->where('type', 'dislike')->count(),
            'repliesCount' => (int) DB::table('comments')->where('parent_id', $this->id)->count(),
            'user' => new ProfileResource($this->whenLoaded('user')),
            'currentUserState' => [
                'liked' => $user ? DB::table('comment_interactions')->where('comment_id', $this->id)->where('user_id', $user->id)->where('type', 'like')->exists() : false,
                'disliked' => $user ? DB::table('comment_interactions')->where('comment_id', $this->id)->where('user_id', $user->id)->where('type', 'dislike')->exists() : false,
            ],
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}