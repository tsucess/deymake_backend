<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademyLessonCompletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'academy_enrollment_id',
        'academy_lesson_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(AcademyEnrollment::class, 'academy_enrollment_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(AcademyLesson::class, 'academy_lesson_id');
    }
}