<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => (int) $this->quantity,
            'unitPriceAmount' => (int) $this->unit_price_amount,
            'totalAmount' => (int) $this->total_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'shippingAddress' => $this->shipping_address ?? [],
            'notes' => $this->notes,
            'placedAt' => $this->placed_at?->toISOString(),
            'fulfilledAt' => $this->fulfilled_at?->toISOString(),
            'cancelledAt' => $this->cancelled_at?->toISOString(),
            'product' => $this->whenLoaded('product', fn () => new MerchProductResource($this->product)),
            'creator' => $this->whenLoaded('creator', fn () => new ProfileResource($this->creator)),
            'buyer' => $this->whenLoaded('buyer', fn () => new ProfileResource($this->buyer)),
        ];
    }
}