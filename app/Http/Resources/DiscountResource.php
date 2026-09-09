<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'value' => (int) $this->value,
            'isActive' => (bool) $this->is_active,
            'usageCount' => (int) $this->usage_count,
            'usageLimit' => $this->usage_limit === null ? null : (int) $this->usage_limit,
            'perUserLimit' => $this->per_user_limit === null ? null : (int) $this->per_user_limit,
            'minOrderAmount' => (int) $this->min_order_amount,
            'expiresAt' => $this->expires_at?->toISOString(),
            'productIds' => $this->product_ids ?? [],
            'creatorIds' => $this->creator_ids ?? [],
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
