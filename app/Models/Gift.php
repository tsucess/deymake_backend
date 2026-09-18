<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gift extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'image_url',
        'description',
        'animation_url',
        'rarity',
        'launch_at',
        'coin_cost',
        'price_amount',
        'currency',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'coin_cost' => 'integer',
            'price_amount' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'launch_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftTransaction::class);
    }
}
