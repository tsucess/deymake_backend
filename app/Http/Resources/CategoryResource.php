<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->name,
            'slug' => $this->slug,
            'thumbnailUrl' => $this->thumbnail_url,
            'subscriberCount' => (int) $this->subscribers_count,
            'subscribers' => number_format((int) $this->subscribers_count / 1000, 1).'k Subscribers',
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}