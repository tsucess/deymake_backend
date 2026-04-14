<?php

namespace App\Http\Requests\Collaboration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCollaborationDeliverableRequest extends FormRequest
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
            'action' => ['required', Rule::in(['save', 'submit', 'request_changes', 'approve', 'cancel'])],
            'title' => ['nullable', 'string', 'max:255'],
            'brief' => ['nullable', 'string', 'max:1000'],
            'feedback' => ['nullable', 'string', 'max:1000'],
            'draftVideoId' => ['nullable', 'integer', 'exists:videos,id'],
        ];
    }
}