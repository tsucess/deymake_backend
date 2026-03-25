<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WaitlistEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullName' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'describes' => $this->describes,
            'loveToSee' => $this->love_to_see,
            'agreed' => (bool) $this->agreed_to_contact,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}