<?php

namespace App\Http\Requests\Challenge;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChallengeRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('challenges', 'slug')],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'bannerUrl' => ['nullable', 'string', 'max:2048'],
            'thumbnailUrl' => ['nullable', 'string', 'max:2048'],
            'rules' => ['nullable', 'array'],
            'rules.*' => ['nullable', 'string', 'max:500'],
            'prizes' => ['nullable', 'array'],
            'prizes.*' => ['nullable', 'string', 'max:500'],
            'requirements' => ['nullable', 'array'],
            'requirements.*' => ['nullable', 'string', 'max:500'],
            'judgingCriteria' => ['nullable', 'array'],
            'judgingCriteria.*' => ['nullable', 'string', 'max:500'],
            'submissionStartsAt' => ['required', 'date'],
            'submissionEndsAt' => ['nullable', 'date', 'after_or_equal:submissionStartsAt'],
            'maxSubmissionsPerUser' => ['nullable', 'integer', 'min:1', 'max:25'],
            'isFeatured' => ['nullable', 'boolean'],
        ];
    }
}