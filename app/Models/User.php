<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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

    public function scopeWithProfileAggregates(Builder $query, ?self $viewer = null): Builder
    {
        return $query
            ->withCount('subscribers')
            ->when($viewer, function (Builder $builder) use ($viewer): void {
                $builder->withExists([
                    'subscribers as subscribed_by_current_user' => fn (Builder $subscribersQuery) => $subscribersQuery->whereKey($viewer->id),
                ]);
            });
    }
}
