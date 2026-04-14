<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevenueShareSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'grossAmount' => (int) $this->gross_amount,
            'sharedAmount' => (int) $this->shared_amount,
            'currency' => $this->currency,
            'sharePercentage' => (int) $this->share_percentage,
            'notes' => $this->notes,
            'settledAt' => $this->settled_at?->toISOString(),
        ];
    }
}