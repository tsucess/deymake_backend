<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayoutAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'accountName' => $this->account_name,
            'accountMask' => $this->account_mask,
            'bankName' => $this->bank_name,
            'bankCode' => $this->bank_code,
            'currency' => $this->currency,
            'verifiedAt' => $this->verified_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}