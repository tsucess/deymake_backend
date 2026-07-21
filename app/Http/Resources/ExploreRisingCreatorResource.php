<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExploreRisingCreatorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $creator = $this->resource['creator'];
        $recentVideos = $this->resource['recentVideos'] ?? collect();

        return [
            'profile' => new ProfileResource($creator),
            'role' => $this->resource['role'],
            'engagementScore' => (int) ($this->resource['engagementScore'] ?? 0),
            'recentVideos' => $recentVideos->map(fn ($video) => [
                'id' => $video->id,
                'publicId' => $video->public_id,
                'thumbnailUrl' => $video->thumbnail_url ?: $video->media_url,
            ])->values()->all(),
        ];
    }
}
