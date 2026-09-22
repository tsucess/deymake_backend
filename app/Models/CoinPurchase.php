<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoinPurchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'coin_package_id',
        'coins',
        'amount',
        'currency',
        'status',
        'payment_provider',
        'payment_reference',
        'is_flagged',
        'flag_reason',
        'reviewed_by',
        'reviewed_at',
        'refunded_at',
        'metadata',
        'purchased_at',
    ];

    protected function casts(): array
    {
        return [
            'coins' => 'integer',
            'amount' => 'integer',
            'is_flagged' => 'boolean',
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'purchased_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
