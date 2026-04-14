<?php

namespace App\Http\Requests\Challenge;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChallengeRequest extends FormRequest
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
        $challengeId = $this->route('challenge')?->id;

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('challenges', 'slug')->ignore($challengeId)],
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
            'submissionStartsAt' => ['sometimes', 'date'],
            'submissionEndsAt' => ['nullable', 'date', 'after_or_equal:submissionStartsAt'],
            'maxSubmissionsPerUser' => ['sometimes', 'integer', 'min:1', 'max:25'],
            'isFeatured' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'closed'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->has('submissionEndsAt')) {
                return;
            }

            $challenge = $this->route('challenge');
            $startsAt = $this->input('submissionStartsAt', $challenge?->submission_starts_at?->toISOString());
            $endsAt = $this->input('submissionEndsAt');

            if ($startsAt && $endsAt && strtotime((string) $endsAt) < strtotime((string) $startsAt)) {
                $validator->errors()->add('submissionEndsAt', __('validation.after_or_equal', ['date' => 'submissionStartsAt']));
            }
        });
    }
}