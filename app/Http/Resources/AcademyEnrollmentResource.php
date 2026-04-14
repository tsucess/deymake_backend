<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademyEnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = (int) ($this->total_lessons_count ?? $this->course?->lessons?->count() ?? 0);
        $completed = (int) ($this->completed_lessons_count ?? $this->completions_count ?? 0);

        return [
            'id' => $this->id,
            'enrolledAt' => $this->enrolled_at?->toISOString(),
            'completedAt' => $this->completed_at?->toISOString(),
            'totalLessons' => $total,
            'completedLessons' => $completed,
            'progressPercent' => $total > 0 ? (int) floor(($completed / $total) * 100) : 0,
            'course' => $this->whenLoaded('course', fn () => new AcademyCourseResource($this->course)),
        ];
    }
}