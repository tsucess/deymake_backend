<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

class Video extends Model
{
    use HasFactory;

    protected const PUBLIC_ID_LENGTH = 12;

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
        'moderation_status',
        'moderated_by',
        'moderated_at',
        'moderation_notes',
        'live_started_at',
        'live_ended_at',
        'live_notified_at',
        'views_count',
        'shares_count',
        'live_comments_count',
        'live_peak_viewers_count',
    ];

    protected function casts(): array
    {
        return [
            'tagged_users' => 'array',
            'is_live' => 'boolean',
            'is_draft' => 'boolean',
            'moderated_at' => 'datetime',
            'live_started_at' => 'datetime',
            'live_ended_at' => 'datetime',
            'live_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $video): void {
            if (filled($video->public_id)) {
                return;
            }

            $video->public_id = static::generateUniquePublicId();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null && $field !== $this->getRouteKeyName()) {
            return parent::resolveRouteBinding($value, $field);
        }

        $resolvedValue = (string) $value;

        return static::query()
            ->where('public_id', $resolvedValue)
            ->when(
                ctype_digit($resolvedValue),
                fn (Builder $query) => $query->orWhere($this->getQualifiedKeyName(), (int) $resolvedValue)
            )
            ->first();
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

    public function liveSignals(): HasMany
    {
        return $this->hasMany(LiveSignal::class);
    }

    public function liveLikeEvents(): HasMany
    {
        return $this->hasMany(LiveLikeEvent::class);
    }

    public function livePresenceSessions(): HasMany
    {
        return $this->hasMany(LivePresenceSession::class);
    }

    public function fanTips(): HasMany
    {
        return $this->hasMany(FanTip::class);
    }

    public function challengeSubmissions(): HasMany
    {
        return $this->hasMany(ChallengeSubmission::class);
    }

    public function collaborationInvites(): HasMany
    {
        return $this->hasMany(CollaborationInvite::class, 'source_video_id');
    }

    public function collaborationDeliverables(): HasMany
    {
        return $this->hasMany(CollaborationDeliverable::class, 'draft_video_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(VideoReport::class);
    }

    public function moderationCase(): MorphOne
    {
        return $this->morphOne(ContentModerationCase::class, 'moderatable');
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
                    $userQuery->withProfileAggregates($viewer);
                },
                'category',
                'upload',
            ])
            ->withCount([
                'likes',
                'dislikes',
                'saves',
                'comments as comments_count' => fn (Builder $commentsQuery) => $commentsQuery->where('moderation_status', 'visible'),
                'liveLikeEvents',
                'fanTips as live_tips_count' => fn (Builder $tipsQuery) => $tipsQuery->where('status', 'posted'),
            ])
            ->addSelect([
                'current_viewers_count' => LivePresenceSession::query()
                    ->selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('video_id', 'videos.id')
                    ->where('role', 'audience')
                    ->whereNull('left_at')
                    ->where('last_seen_at', '>=', now()->subSeconds(30)),
                'live_tips_amount' => FanTip::query()
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('video_id', 'videos.id')
                    ->where('status', 'posted'),
            ])
            ->when($viewer, function (Builder $videoQuery) use ($viewer): void {
                $videoQuery->withExists([
                    'likes as liked_by_current_user' => fn (Builder $likesQuery) => $likesQuery->whereKey($viewer->id),
                    'dislikes as disliked_by_current_user' => fn (Builder $dislikesQuery) => $dislikesQuery->whereKey($viewer->id),
                    'saves as saved_by_current_user' => fn (Builder $savesQuery) => $savesQuery->whereKey($viewer->id),
                ]);
            });
    }

    public function scopeDiscoverable(Builder $query): Builder
    {
        return $query
            ->where('is_draft', false)
            ->where('moderation_status', 'visible');
    }

    public function isVisibleTo(?User $viewer): bool
    {
        if ($viewer?->isAdmin()) {
            return true;
        }

        if ($viewer && $viewer->id === $this->user_id) {
            return true;
        }

        return ! $this->is_draft && $this->moderation_status === 'visible';
    }

    protected static function generateUniquePublicId(): string
    {
        do {
            $publicId = Str::lower(Str::random(self::PUBLIC_ID_LENGTH));
        } while (static::query()->where('public_id', $publicId)->exists());

        return $publicId;
    }
}