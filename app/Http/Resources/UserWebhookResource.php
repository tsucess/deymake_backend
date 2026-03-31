<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserWebhookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'targetUrl' => $this->target_url,
            'events' => $this->events ?? [],
            'isActive' => (bool) $this->is_active,
            'hasSecret' => filled($this->getRawOriginal('secret')),
            'lastTriggeredAt' => $this->last_triggered_at?->toISOString(),
            'lastStatusCode' => $this->last_status_code,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}