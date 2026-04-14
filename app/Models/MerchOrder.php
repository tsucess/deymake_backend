<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'merch_product_id',
        'creator_id',
        'buyer_id',
        'quantity',
        'unit_price_amount',
        'total_amount',
        'currency',
        'status',
        'shipping_address',
        'notes',
        'placed_at',
        'fulfilled_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'placed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchProduct::class, 'merch_product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}