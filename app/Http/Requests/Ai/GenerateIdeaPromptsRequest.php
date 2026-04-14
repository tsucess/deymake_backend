<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateIdeaPromptsRequest extends FormRequest
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
            'topic' => ['nullable', 'string', 'max:255'],
            'goal' => ['nullable', Rule::in(['growth', 'engagement', 'education', 'promotion', 'community'])],
            'format' => ['nullable', Rule::in(['video', 'series', 'challenge', 'duet', 'live', 'tutorial', 'behind_the_scenes'])],
            'tone' => ['nullable', Rule::in(['bold', 'playful', 'inspiring', 'funny', 'smooth', 'confident'])],
            'audience' => ['nullable', 'string', 'max:120'],
            'categoryId' => ['nullable', 'integer', 'exists:categories,id'],
            'keywords' => ['nullable', 'array'],
            'keywords.*' => ['nullable', 'string', 'max:40'],
            'count' => ['nullable', 'integer', 'min:1', 'max:6'],
        ];
    }
}