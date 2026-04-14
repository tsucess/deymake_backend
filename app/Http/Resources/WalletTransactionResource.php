<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'direction' => $this->direction,
            'status' => $this->status,
            'amount' => (int) $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'occurredAt' => $this->occurred_at?->toISOString(),
            'membershipId' => $this->membership_id,
            'payoutRequestId' => $this->payout_request_id,
        ];
    }
}