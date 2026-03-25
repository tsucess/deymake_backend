<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'text' => $this->body,
            'isMine' => $request->user()?->id === $this->user_id,
            'sender' => new ProfileResource($this->whenLoaded('user')),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}