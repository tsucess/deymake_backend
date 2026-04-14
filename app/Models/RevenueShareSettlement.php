<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueShareSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'revenue_share_agreement_id',
        'created_by',
        'gross_amount',
        'shared_amount',
        'currency',
        'share_percentage',
        'notes',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'settled_at' => 'datetime',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(RevenueShareAgreement::class, 'revenue_share_agreement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}