<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'priceAmount' => (int) $this->price_amount,
            'currency' => $this->currency,
            'billingPeriod' => $this->billing_period,
            'benefits' => $this->benefits ?? [],
            'isActive' => (bool) $this->is_active,
            'sortOrder' => (int) $this->sort_order,
            'memberCount' => (int) ($this->memberships_count ?? 0),
            'activeMemberCount' => (int) ($this->active_memberships_count ?? 0),
            'currentUserMembership' => $this->when(isset($this->current_user_membership), $this->current_user_membership),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}