<?php

namespace App\Http\Requests\Collaboration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCollaborationInviteRequest extends FormRequest
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
            'inviteeId' => ['required', 'integer', 'exists:users,id'],
            'videoId' => ['required', 'integer', 'exists:videos,id'],
            'type' => ['nullable', Rule::in(['duet', 'remix', 'collab'])],
            'message' => ['nullable', 'string', 'max:1000'],
            'expiresInDays' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}