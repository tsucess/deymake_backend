<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FanTip extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'fan_id',
        'video_id',
        'amount',
        'currency',
        'status',
        'message',
        'is_private',
        'metadata',
        'tipped_at',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'metadata' => 'array',
            'tipped_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function fan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fan_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}