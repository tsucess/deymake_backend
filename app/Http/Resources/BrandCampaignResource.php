<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'businessName' => $this->business_name,
            'title' => $this->title,
            'objective' => $this->objective,
            'status' => $this->status,
            'summary' => $this->summary,
            'description' => $this->summary,
            'niche' => $this->niche,
            'budgetAmount' => (int) $this->budget_amount,
            'currency' => $this->currency,
            'creatorsNeeded' => (int) $this->creators_needed,
            'creatorRequirements' => $this->creator_requirements,
            'minSubscribers' => (int) $this->min_subscribers,
            'targetCategories' => $this->target_categories ?? [],
            'targetLocations' => $this->target_locations ?? [],
            'deliverables' => $this->deliverables ?? [],
            'startsAt' => $this->starts_at?->toISOString(),
            'endsAt' => $this->ends_at?->toISOString(),
            'owner' => $this->whenLoaded('owner', fn () => new ProfileResource($this->owner)),
        ];
    }
}
