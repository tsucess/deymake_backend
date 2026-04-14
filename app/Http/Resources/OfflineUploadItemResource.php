<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfflineUploadItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clientReference' => $this->client_reference,
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'failureReason' => $this->failure_reason,
            'metadata' => $this->metadata ?? [],
            'uploadId' => $this->upload_id,
            'videoId' => $this->video_id,
            'lastSyncedAt' => $this->last_synced_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}