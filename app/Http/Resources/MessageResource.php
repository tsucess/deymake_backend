<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attachment = $this->attachment_url ? [
            'url' => $this->attachment_url,
            'type' => $this->attachment_type,
            'name' => $this->attachment_name,
            'mime' => $this->attachment_mime,
            'size' => $this->attachment_size !== null ? (int) $this->attachment_size : null,
        ] : null;

        return [
            'id' => $this->id,
            'body' => $this->body,
            'text' => $this->body,
            'attachment' => $attachment,
            'isMine' => $request->user()?->id === $this->user_id,
            'sender' => new ProfileResource($this->whenLoaded('user')),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}