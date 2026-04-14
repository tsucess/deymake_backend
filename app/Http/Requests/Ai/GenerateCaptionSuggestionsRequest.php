<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateCaptionSuggestionsRequest extends FormRequest
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
            'context' => ['nullable', 'string', 'max:1000'],
            'categoryId' => ['nullable', 'integer', 'exists:categories,id'],
            'tone' => ['nullable', Rule::in(['bold', 'playful', 'inspiring', 'funny', 'smooth', 'confident'])],
            'goal' => ['nullable', Rule::in(['engagement', 'storytelling', 'promotion', 'challenge', 'community'])],
            'audience' => ['nullable', 'string', 'max:120'],
            'keywords' => ['nullable', 'array'],
            'keywords.*' => ['nullable', 'string', 'max:40'],
            'includeHashtags' => ['nullable', 'boolean'],
            'count' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];
    }
}