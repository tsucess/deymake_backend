<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayoutRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (int) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'notes' => $this->notes,
            'rejectionReason' => $this->rejection_reason,
            'externalReference' => $this->external_reference,
            'requestedAt' => $this->requested_at?->toISOString(),
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'processedAt' => $this->processed_at?->toISOString(),
            'account' => $this->whenLoaded('payoutAccount', fn () => $this->payoutAccount ? new PayoutAccountResource($this->payoutAccount) : null),
            'creator' => $this->whenLoaded('user', fn () => $this->user ? new UserResource($this->user) : null),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? new UserResource($this->reviewer) : null),
        ];
    }
}