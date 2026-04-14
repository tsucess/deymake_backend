<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiEditingProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'operations' => $this->operations ?? [],
            'output' => $this->output,
            'sourceVideoId' => $this->source_video_id,
            'sourceUploadId' => $this->source_upload_id,
            'generatedAt' => $this->generated_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}