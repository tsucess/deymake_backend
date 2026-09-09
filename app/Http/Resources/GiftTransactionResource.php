<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin gift-transaction resource.
 *
 * Shapes a single gift-sending record for the admin Coins & Gifts console,
 * including sender/recipient profiles and fraud-review + refund state.
 */
class GiftTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'giftId' => $this->gift_id,
            'giftName' => $this->gift_name,
            'senderId' => (int) $this->sender_id,
            'recipientId' => (int) $this->recipient_id,
            'videoId' => $this->video_id,
            'quantity' => (int) $this->quantity,
            'coinAmount' => (int) $this->coin_amount,
            'creatorEarnings' => (int) $this->creator_earnings,
            'currency' => $this->currency,
            'status' => $this->status,
            'isFlagged' => (bool) $this->is_flagged,
            'flagReason' => $this->flag_reason,
            'reviewedBy' => $this->reviewed_by,
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'refundedBy' => $this->refunded_by,
            'refundedAt' => $this->refunded_at?->toISOString(),
            'sentAt' => $this->sent_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'sender' => $this->whenLoaded('sender', fn () => new ProfileResource($this->sender)),
            'recipient' => $this->whenLoaded('recipient', fn () => new ProfileResource($this->recipient)),
        ];
    }
}
