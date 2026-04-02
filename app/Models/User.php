<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Username;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'email_verified_at',
        'password',
        'avatar_url',
        'bio',
        'preferences',
        'is_online',
        'provider',
        'provider_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'is_online' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $fallback = Str::before((string) $user->email, '@');
            $seed = (string) ($user->username ?: $user->name ?: $fallback ?: 'user');

            $user->username = Username::unique(
                $seed,
                static fn (string $candidate): bool => self::query()
                    ->where('username', $candidate)
                    ->exists(),
                $fallback !== '' ? $fallback : 'user',
            );
        });
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'subscriptions', 'creator_id', 'user_id')
            ->withTimestamps();
    }

    public function subscribedCreators(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'subscriptions', 'user_id', 'creator_id')
            ->withTimestamps();
    }

    public function creatorPlans(): HasMany
    {
        return $this->hasMany(CreatorPlan::class, 'creator_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'member_id');
    }

    public function managedMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'creator_id');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(UserWebhook::class);
    }

    public function scopeWithProfileAggregates(Builder $query, ?self $viewer = null): Builder
    {
        return $query
            ->withCount('subscribers')
            ->withCount([
                'creatorPlans',
                'creatorPlans as active_creator_plans_count' => fn (Builder $plansQuery) => $plansQuery->where('is_active', true),
                'webhooks',
                'tokens',
            ])
            ->when($viewer, function (Builder $builder) use ($viewer): void {
                $builder->withExists([
                    'subscribers as subscribed_by_current_user' => fn (Builder $subscribersQuery) => $subscribersQuery->whereKey($viewer->id),
                ]);
            });
    }
}
