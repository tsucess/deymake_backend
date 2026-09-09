<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'gift_id',
        'gift_name',
        'sender_id',
        'recipient_id',
        'video_id',
        'quantity',
        'coin_amount',
        'creator_earnings',
        'currency',
        'status',
        'is_flagged',
        'flag_reason',
        'reviewed_by',
        'reviewed_at',
        'refunded_by',
        'refunded_at',
        'metadata',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'coin_amount' => 'integer',
            'creator_earnings' => 'integer',
            'is_flagged' => 'boolean',
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function refunder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }
}
