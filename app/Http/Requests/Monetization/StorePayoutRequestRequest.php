<?php

namespace App\Http\Requests\Monetization;

use Illuminate\Foundation\Http\FormRequest;

class StorePayoutRequestRequest extends FormRequest
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
            'amount' => ['required', 'integer', 'min:100'],
            'payoutAccountId' => ['nullable', 'integer', 'exists:payout_accounts,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}