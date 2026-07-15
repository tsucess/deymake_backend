<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Story extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'upload_id',
        'type',
        'media_url',
        'thumbnail_url',
        'caption',
        'views_count',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'story_views')->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeWithViewerData(Builder $query, ?User $viewer = null): Builder
    {
        return $query
            ->with('user')
            ->when($viewer, function (Builder $storyQuery) use ($viewer): void {
                $storyQuery->withExists([
                    'viewers as seen_by_current_user' => fn (Builder $viewerQuery) => $viewerQuery->whereKey($viewer->id),
                ]);
            });
    }
}
