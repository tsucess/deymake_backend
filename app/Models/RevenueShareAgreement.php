<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevenueShareAgreement extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'recipient_id',
        'title',
        'source_type',
        'share_percentage',
        'currency',
        'status',
        'notes',
        'metadata',
        'accepted_at',
        'cancelled_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(RevenueShareSettlement::class);
    }
}