<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AcademyCourse extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'status',
        'difficulty',
        'thumbnail_url',
        'summary',
        'description',
        'estimated_minutes',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $course): void {
            if (! $course->slug) {
                $base = Str::slug($course->title ?: 'academy-course');
                $course->slug = self::query()->where('slug', $base)->exists()
                    ? $base.'-'.Str::lower(Str::random(4))
                    : $base;
            }
        });
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(AcademyLesson::class)->orderBy('sort_order')->orderBy('id');
    }
}