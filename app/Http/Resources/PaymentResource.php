<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin payment resource.
 *
 * Shapes a single provider-processed payment for the admin Payments console,
 * including payer profile, provider metadata, and reconciliation/review state.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'provider' => $this->provider,
            'providerReference' => $this->provider_reference,
            'userId' => $this->user_id,
            'email' => $this->email,
            'purpose' => $this->purpose,
            'purposeType' => $this->purpose_type,
            'purposeId' => $this->purpose_id,
            'amount' => (int) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'channel' => $this->channel,
            'fees' => (int) $this->fees,
            'authorizationUrl' => $this->authorization_url,
            'gatewayResponse' => $this->gateway_response,
            'isFlagged' => (bool) $this->is_flagged,
            'flagReason' => $this->flag_reason,
            'reviewedBy' => $this->reviewed_by,
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'metadata' => $this->metadata,
            'paidAt' => $this->paid_at?->toISOString(),
            'refundedAt' => $this->refunded_at?->toISOString(),
            'reconciledAt' => $this->reconciled_at?->toISOString(),
            'reconciliationNote' => $this->reconciliation_note,
            'createdAt' => $this->created_at?->toISOString(),
            'user' => $this->whenLoaded('user', fn () => $this->user ? new ProfileResource($this->user) : null),
        ];
    }
}
