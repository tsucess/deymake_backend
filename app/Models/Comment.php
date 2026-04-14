<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id',
        'user_id',
        'parent_id',
        'body',
        'moderation_status',
        'moderated_by',
        'moderated_at',
        'moderation_notes',
    ];

    protected function casts(): array
    {
        return [
            'moderated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function moderationCase(): MorphOne
    {
        return $this->morphOne(ContentModerationCase::class, 'moderatable');
    }

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'comment_interactions')
            ->wherePivot('type', '=', 'like')
            ->withTimestamps();
    }

    public function dislikes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'comment_interactions')
            ->wherePivot('type', '=', 'dislike')
            ->withTimestamps();
    }

    public function scopeWithApiResourceData(Builder $query, ?User $viewer = null): Builder
    {
        return $query
            ->with([
                'user' => fn ($userQuery) => $userQuery->withProfileAggregates(),
            ])
            ->withCount([
                'likes',
                'dislikes',
                'replies as replies_count' => fn (Builder $repliesQuery) => $repliesQuery->where('moderation_status', 'visible'),
            ])
            ->when($viewer, function (Builder $commentQuery) use ($viewer): void {
                $commentQuery->withExists([
                    'likes as liked_by_current_user' => fn (Builder $likesQuery) => $likesQuery->whereKey($viewer->id),
                    'dislikes as disliked_by_current_user' => fn (Builder $dislikesQuery) => $dislikesQuery->whereKey($viewer->id),
                ]);
            });
    }

    public function scopeVisibleTo(Builder $query, ?User $viewer = null): Builder
    {
        if ($viewer?->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($viewer): void {
            $builder->where('moderation_status', 'visible');

            if ($viewer) {
                $builder->orWhere('user_id', $viewer->id);
            }
        });
    }
}