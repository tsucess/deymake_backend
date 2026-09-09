<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->order_id,
            'userId' => $this->user_id,
            'amount' => (int) $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'status' => $this->status,
            'adminNotes' => $this->admin_notes,
            'reviewedBy' => $this->reviewed_by,
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'processedBy' => $this->processed_by,
            'processedAt' => $this->processed_at?->toISOString(),
            'paymentReference' => $this->payment_reference,
            'providerResponse' => $this->provider_response,
            'requestedAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            'order' => $this->whenLoaded('order', fn () => new MerchOrderResource($this->order)),
            'user' => $this->whenLoaded('user', fn () => new ProfileResource($this->user)),
            'reviewer' => $this->whenLoaded('reviewer', fn () => new ProfileResource($this->reviewer)),
            'processor' => $this->whenLoaded('processor', fn () => new ProfileResource($this->processor)),
        ];
    }
}
