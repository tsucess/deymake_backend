<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'discount_code',
        'discount_amount',
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
            'discount_amount' => 'integer',
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

    /**
     * Provider payments recorded against this order (purpose `merch_order`).
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'purpose_id')->where('purpose', 'merch_order');
    }
}
