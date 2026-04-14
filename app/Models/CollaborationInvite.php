<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollaborationInvite extends Model
{
    use HasFactory;

    protected $fillable = [
        'inviter_id',
        'invitee_id',
        'source_video_id',
        'conversation_id',
        'type',
        'status',
        'message',
        'metadata',
        'responded_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }

    public function sourceVideo(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'source_video_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(CollaborationDeliverable::class);
    }
}