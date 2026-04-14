<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateManagedUserRequest extends FormRequest
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
            'accountStatus' => ['sometimes', Rule::in(['active', 'suspended'])],
            'accountStatusNotes' => ['nullable', 'string', 'max:1000'],
            'isAdmin' => ['sometimes', 'boolean'],
            'clearSessions' => ['sometimes', 'boolean'],
        ];
    }
}