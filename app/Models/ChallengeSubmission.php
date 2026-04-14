<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'challenge_id',
        'user_id',
        'video_id',
        'title',
        'caption',
        'description',
        'media_url',
        'thumbnail_url',
        'external_url',
        'metadata',
        'status',
        'submitted_at',
        'reviewed_at',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function scopeWithApiResourceData(Builder $query, ?User $viewer = null): Builder
    {
        return $query->with([
            'user' => fn ($userQuery) => $userQuery->withProfileAggregates($viewer),
            'challenge.host' => fn ($hostQuery) => $hostQuery->withProfileAggregates($viewer),
            'video.user' => fn ($userQuery) => $userQuery->withProfileAggregates($viewer),
        ]);
    }
}