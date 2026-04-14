<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademyLessonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'content' => $this->content,
            'sortOrder' => (int) $this->sort_order,
            'durationMinutes' => (int) $this->duration_minutes,
            'status' => $this->status,
            'publishedAt' => $this->published_at?->toISOString(),
        ];
    }
}