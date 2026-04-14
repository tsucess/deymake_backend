<?php

namespace App\Http\Requests\Monetization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayoutAccountRequest extends FormRequest
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
            'provider' => ['nullable', Rule::in(['bank_transfer', 'mobile_money', 'wallet'])],
            'accountName' => ['required', 'string', 'max:255'],
            'accountReference' => ['required', 'string', 'max:120'],
            'bankName' => ['nullable', 'string', 'max:120'],
            'bankCode' => ['nullable', 'string', 'max:40'],
            'currency' => ['nullable', 'string', 'size:3'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}