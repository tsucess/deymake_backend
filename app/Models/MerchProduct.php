<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MerchProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'name',
        'slug',
        'status',
        'sku',
        'description',
        'price_amount',
        'currency',
        'inventory_count',
        'images',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            if (! $product->slug) {
                $base = Str::slug($product->name ?: 'merch-product');
                $product->slug = self::query()->where('slug', $base)->exists()
                    ? $base.'-'.Str::lower(Str::random(4))
                    : $base;
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MerchOrder::class);
    }
}