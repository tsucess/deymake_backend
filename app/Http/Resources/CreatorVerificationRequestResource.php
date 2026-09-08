<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorVerificationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Submitted identity documents are sensitive: expose the URL only to the
        // applicant themselves or to an authenticated administrator.
        $viewer = $request->user();
        $canViewDocument = $viewer !== null
            && ((int) $viewer->id === (int) $this->user_id || $viewer->isAdmin());

        return [
            'id' => $this->id,
            'status' => $this->status,
            'legalName' => $this->legal_name,
            'country' => $this->country,
            'documentType' => $this->document_type,
            'documentUrl' => $canViewDocument ? $this->document_url : null,
            'documentAccessRestricted' => ! $canViewDocument,
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
