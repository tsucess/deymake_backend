<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminReportRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in(['pending', 'reviewed', 'dismissed', 'escalated'])],
            'adminNotes' => ['nullable', 'string', 'max:2000'],
            'contentAction' => ['sometimes', Rule::in(['restrict', 'remove', 'restore'])],
            'notifyReporter' => ['sometimes', 'boolean'],
        ];
    }
}
