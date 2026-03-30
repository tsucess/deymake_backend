<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'disk' => $this->disk,
            'path' => $this->path,
            'url' => $this->url,
            'processedUrl' => $this->processed_url,
            'originalName' => $this->original_name,
            'mimeType' => $this->mime_type,
            'size' => (int) $this->size,
            'processingStatus' => $this->processing_status,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}