<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin coin-purchase resource.
 *
 * Shapes a single coin purchase record for the admin Coins & Gifts console,
 * including buyer profile, package snapshot, and fraud-review state.
 */
class CoinPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => (int) $this->user_id,
            'coins' => (int) $this->coins,
            'amount' => (int) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'paymentProvider' => $this->payment_provider,
            'paymentReference' => $this->payment_reference,
            'isFlagged' => (bool) $this->is_flagged,
            'flagReason' => $this->flag_reason,
            'reviewedBy' => $this->reviewed_by,
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'refundedAt' => $this->refunded_at?->toISOString(),
            'purchasedAt' => $this->purchased_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'user' => $this->whenLoaded('user', fn () => new ProfileResource($this->user)),
        ];
    }
}
