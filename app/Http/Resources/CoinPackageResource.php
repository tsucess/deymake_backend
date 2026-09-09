<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin coin-package resource.
 *
 * Shapes a coin package for the admin Coins & Gifts console, including the
 * derived sales/revenue aggregates attached by AdminCoinController::packages.
 */
class CoinPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'coins' => (int) $this->coins,
            'bonusCoins' => (int) $this->bonus_coins,
            'totalCoins' => (int) $this->coins + (int) $this->bonus_coins,
            'priceAmount' => (int) $this->price_amount,
            'currency' => $this->currency,
            'isActive' => (bool) $this->is_active,
            'sortOrder' => (int) $this->sort_order,
            'sales' => (int) ($this->sales_count ?? 0),
            'revenue' => (int) ($this->revenue_amount ?? 0),
            'coinsSold' => (int) ($this->coins_sold ?? 0),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
