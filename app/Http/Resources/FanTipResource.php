<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FanTipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (int) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'message' => $this->message,
            'isPrivate' => (bool) $this->is_private,
            'metadata' => $this->metadata ?? [],
            'tippedAt' => $this->tipped_at?->toISOString(),
            'fan' => $this->whenLoaded('fan', fn () => new ProfileResource($this->fan)),
            'creator' => $this->whenLoaded('creator', fn () => new ProfileResource($this->creator)),
            'video' => $this->whenLoaded('video', fn () => $this->video ? [
                'id' => $this->video->id,
                'title' => $this->video->title,
                'isLive' => (bool) $this->video->is_live,
            ] : null),
        ];
    }
}