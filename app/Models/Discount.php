<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'type',
        'value',
        'is_active',
        'usage_count',
        'usage_limit',
        'per_user_limit',
        'min_order_amount',
        'expires_at',
        'product_ids',
        'creator_ids',
        'used_user_ids',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'value' => 'integer',
            'usage_count' => 'integer',
            'usage_limit' => 'integer',
            'per_user_limit' => 'integer',
            'min_order_amount' => 'integer',
            'product_ids' => 'array',
            'creator_ids' => 'array',
            'used_user_ids' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MerchOrder::class);
    }
}
