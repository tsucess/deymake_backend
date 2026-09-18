<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Challenge extends Model
{
    use HasFactory;

    protected const PUBLIC_ID_LENGTH = 12;

    protected $fillable = [
        'host_id',
        'title',
        'slug',
        'summary',
        'category',
        'description',
        'banner_url',
        'thumbnail_url',
        'rules',
        'prizes',
        'requirements',
        'judging_criteria',
        'submission_starts_at',
        'submission_ends_at',
        'status',
        'is_featured',
        'max_submissions_per_user',
        'published_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'prizes' => 'array',
            'requirements' => 'array',
            'judging_criteria' => 'array',
            'submission_starts_at' => 'datetime',
            'submission_ends_at' => 'datetime',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $challenge): void {
            if (! $challenge->slug) {
                $challenge->slug = self::generateUniqueSlug($challenge->title ?: 'challenge');
            }

            if (! filled($challenge->public_id)) {
                $challenge->public_id = static::generateUniquePublicId();
            }
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

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ChallengeSubmission::class);
    }

    public function scopeWithApiResourceData(Builder $query, ?User $viewer = null): Builder
    {
        return $query
            ->with([
                'host' => fn ($hostQuery) => $hostQuery->withProfileAggregates($viewer),
            ])
            ->withCount([
                'submissions as submissions_count' => fn (Builder $submissionsQuery) => $submissionsQuery->where('status', '!=', 'withdrawn'),
            ])
            ->when($viewer, function (Builder $builder) use ($viewer): void {
                $builder->withCount([
                    'submissions as current_user_submissions_count' => fn (Builder $submissionsQuery) => $submissionsQuery
                        ->where('user_id', $viewer->id)
                        ->where('status', '!=', 'withdrawn'),
                ]);
            });
    }

    public function scopeVisibleTo(Builder $query, ?User $viewer = null): Builder
    {
        return $query->where(function (Builder $builder) use ($viewer): void {
            $builder->where('status', '!=', 'draft');

            if ($viewer) {
                $builder->orWhere('host_id', $viewer->id);
            }
        });
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isUpcoming(): bool
    {
        return $this->isPublished()
            && $this->submission_starts_at !== null
            && $this->submission_starts_at->isFuture();
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed'
            || ($this->isPublished()
                && $this->submission_ends_at !== null
                && $this->submission_ends_at->isPast());
    }

    public function isOpenForSubmissions(): bool
    {
        return $this->isPublished()
            && ! $this->isUpcoming()
            && ! $this->isClosed();
    }

    public function lifecycleStatus(): string
    {
        if ($this->status === 'draft') {
            return 'draft';
        }

        if ($this->isUpcoming()) {
            return 'upcoming';
        }

        if ($this->isClosed()) {
            return 'closed';
        }

        return 'active';
    }

    public static function generateUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        $base = $base !== '' ? $base : 'challenge';
        $slug = $base;
        $suffix = 2;

        while (self::query()
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    protected static function generateUniquePublicId(): string
    {
        do {
            $publicId = Str::lower(Str::random(self::PUBLIC_ID_LENGTH));
        } while (static::query()->where('public_id', $publicId)->exists());

        return $publicId;
    }
}
