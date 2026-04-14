<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePayoutRequestStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(['requested', 'processing', 'paid', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'rejectionReason' => ['nullable', 'string', 'max:1000'],
            'externalReference' => ['nullable', 'string', 'max:255'],
        ];
    }
}