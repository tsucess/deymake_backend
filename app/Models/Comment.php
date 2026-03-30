<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id',
        'user_id',
        'parent_id',
        'body',
    ];

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
            ->withCount(['likes', 'dislikes', 'replies'])
            ->when($viewer, function (Builder $commentQuery) use ($viewer): void {
                $commentQuery->withExists([
                    'likes as liked_by_current_user' => fn (Builder $likesQuery) => $likesQuery->whereKey($viewer->id),
                    'dislikes as disliked_by_current_user' => fn (Builder $dislikesQuery) => $dislikesQuery->whereKey($viewer->id),
                ]);
            });
    }
}