<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TalentDiscoveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'profile' => new ProfileResource($this->resource),
            'metrics' => [
                'publishedVideos' => (int) ($this->published_videos_count ?? 0),
                'publishedViews' => (int) ($this->published_views_count ?? 0),
                'activePlans' => (int) ($this->active_creator_plans_count ?? 0),
            ],
        ];
    }
}