<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'sku' => $this->sku,
            'description' => $this->description,
            'priceAmount' => (int) $this->price_amount,
            'currency' => $this->currency,
            'inventoryCount' => (int) $this->inventory_count,
            'images' => $this->images ?? [],
            'creator' => $this->whenLoaded('creator', fn () => new ProfileResource($this->creator)),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}