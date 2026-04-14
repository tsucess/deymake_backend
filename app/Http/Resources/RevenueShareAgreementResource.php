<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevenueShareAgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'sourceType' => $this->source_type,
            'sharePercentage' => (int) $this->share_percentage,
            'currency' => $this->currency,
            'status' => $this->status,
            'notes' => $this->notes,
            'metadata' => $this->metadata ?? [],
            'acceptedAt' => $this->accepted_at?->toISOString(),
            'cancelledAt' => $this->cancelled_at?->toISOString(),
            'rejectedAt' => $this->rejected_at?->toISOString(),
            'owner' => $this->whenLoaded('owner', fn () => new ProfileResource($this->owner)),
            'recipient' => $this->whenLoaded('recipient', fn () => new ProfileResource($this->recipient)),
            'settlements' => RevenueShareSettlementResource::collection($this->whenLoaded('settlements')),
        ];
    }
}