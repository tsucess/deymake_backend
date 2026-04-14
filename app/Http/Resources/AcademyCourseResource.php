<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademyCourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status,
            'difficulty' => $this->difficulty,
            'thumbnailUrl' => $this->thumbnail_url,
            'summary' => $this->summary,
            'description' => $this->description,
            'estimatedMinutes' => (int) $this->estimated_minutes,
            'publishedAt' => $this->published_at?->toISOString(),
            'lessonsCount' => (int) ($this->lessons_count ?? $this->lessons?->count() ?? 0),
            'lessons' => AcademyLessonResource::collection($this->whenLoaded('lessons')),
        ];
    }
}