<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SponsorshipProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'brief' => $this->brief,
            'feeAmount' => (int) $this->fee_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'deliverables' => $this->deliverables ?? [],
            'proposedPublishAt' => $this->proposed_publish_at?->toISOString(),
            'respondedAt' => $this->responded_at?->toISOString(),
            'sender' => $this->whenLoaded('sender', fn () => new ProfileResource($this->sender)),
            'recipient' => $this->whenLoaded('recipient', fn () => new ProfileResource($this->recipient)),
            'campaign' => $this->whenLoaded('brandCampaign', fn () => [
                'id' => $this->brandCampaign?->id,
                'title' => $this->brandCampaign?->title,
                'status' => $this->brandCampaign?->status,
            ]),
        ];
    }
}