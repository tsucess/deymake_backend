<?php

namespace App\Http\Requests\Challenge;

use Illuminate\Foundation\Http\FormRequest;

class StoreChallengeSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'videoId' => ['nullable', 'integer', 'exists:videos,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string'],
            'mediaUrl' => ['nullable', 'string', 'max:2048'],
            'thumbnailUrl' => ['nullable', 'string', 'max:2048'],
            'externalUrl' => ['nullable', 'url', 'max:2048'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $hasVideo = $this->filled('videoId');
            $hasMedia = $this->filled('mediaUrl');
            $hasExternal = $this->filled('externalUrl');

            if (! $hasVideo && ! $hasMedia && ! $hasExternal) {
                $validator->errors()->add('videoId', __('messages.challenges.submission_asset_required'));
            }
        });
    }
}