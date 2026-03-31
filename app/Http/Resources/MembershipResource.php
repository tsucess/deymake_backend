<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'priceAmount' => (int) $this->price_amount,
            'currency' => $this->currency,
            'billingPeriod' => $this->billing_period,
            'paymentReference' => $this->payment_reference,
            'startedAt' => $this->started_at?->toISOString(),
            'cancelledAt' => $this->cancelled_at?->toISOString(),
            'endsAt' => $this->ends_at?->toISOString(),
            'plan' => $this->relationLoaded('plan') ? new CreatorPlanResource($this->plan) : null,
            'creator' => $this->relationLoaded('creator') ? new ProfileResource($this->creator) : null,
            'member' => $this->relationLoaded('member') ? new ProfileResource($this->member) : null,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}