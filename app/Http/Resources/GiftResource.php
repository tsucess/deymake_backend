<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin gift-catalog resource.
 *
 * Shapes a catalog gift for the admin Coins & Gifts console, including the
 * derived send/earnings aggregates attached by AdminGiftController::gifts.
 */
class GiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'imageUrl' => $this->image_url,
            'description' => $this->description,
            'animationUrl' => $this->animation_url,
            'rarity' => $this->rarity,
            'launchAt' => $this->launch_at?->toISOString(),
            'coinCost' => (int) $this->coin_cost,
            'priceAmount' => (int) $this->price_amount,
            'currency' => $this->currency,
            'isActive' => (bool) $this->is_active,
            'sortOrder' => (int) $this->sort_order,
            'sentCount' => (int) ($this->sent_count ?? 0),
            'coinsCollected' => (int) ($this->coins_collected ?? 0),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
