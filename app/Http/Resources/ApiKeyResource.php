<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abilities' => $this->abilities ?? ['*'],
            'lastUsedAt' => $this->last_used_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}