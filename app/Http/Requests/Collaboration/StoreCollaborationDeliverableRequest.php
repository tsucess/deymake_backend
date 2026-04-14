<?php

namespace App\Http\Requests\Collaboration;

use Illuminate\Foundation\Http\FormRequest;

class StoreCollaborationDeliverableRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:255'],
            'brief' => ['nullable', 'string', 'max:1000'],
            'draftVideoId' => ['nullable', 'integer', 'exists:videos,id'],
        ];
    }
}