<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'upload_id',
        'type',
        'title',
        'caption',
        'description',
        'location',
        'tagged_users',
        'media_url',
        'thumbnail_url',
        'is_live',
        'is_draft',
        'live_started_at',
        'live_ended_at',
        'live_notified_at',
        'views_count',
        'shares_count',
    ];

    protected function casts(): array
    {
        return [
            'tagged_users' => 'array',
            'is_live' => 'boolean',
            'is_draft' => 'boolean',
            'live_started_at' => 'datetime',
            'live_ended_at' => 'datetime',
            'live_notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'video_interactions')
            ->wherePivot('type', '=', 'like')
            ->withTimestamps();
    }

    public function dislikes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'video_interactions')
            ->wherePivot('type', '=', 'dislike')
            ->withTimestamps();
    }

    public function saves(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'video_interactions')
            ->wherePivot('type', '=', 'save')
            ->withTimestamps();
    }

    public function scopeWithApiResourceData(Builder $query, ?User $viewer = null): Builder
    {
        return $query
            ->with([
                'user' => function ($userQuery) use ($viewer): void {
                    $userQuery->withProfileAggregates();

                    if ($viewer) {
                        $userQuery->withExists([
                            'subscribers as subscribed_by_current_user' => fn (Builder $subscribersQuery) => $subscribersQuery->whereKey($viewer->id),
                        ]);
                    }
                },
                'category',
                'upload',
            ])
            ->withCount(['likes', 'dislikes', 'saves', 'comments'])
            ->when($viewer, function (Builder $videoQuery) use ($viewer): void {
                $videoQuery->withExists([
                    'likes as liked_by_current_user' => fn (Builder $likesQuery) => $likesQuery->whereKey($viewer->id),
                    'dislikes as disliked_by_current_user' => fn (Builder $dislikesQuery) => $dislikesQuery->whereKey($viewer->id),
                    'saves as saved_by_current_user' => fn (Builder $savesQuery) => $savesQuery->whereKey($viewer->id),
                ]);
            });
    }
}