<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorVerificationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'legalName' => $this->legal_name,
            'country' => $this->country,
            'documentType' => $this->document_type,
            'documentUrl' => $this->document_url,
            'about' => $this->about,
            'socialLinks' => $this->social_links ?? [],
            'reviewNotes' => $this->review_notes,
            'submittedAt' => $this->submitted_at?->toISOString(),
            'reviewedAt' => $this->reviewed_at?->toISOString(),
            'user' => $this->whenLoaded('user', fn () => new ProfileResource($this->user)),
            'reviewer' => $this->whenLoaded('reviewer', fn () => new ProfileResource($this->reviewer)),
        ];
    }
}