<?php

namespace App\Http\Requests\Discount;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'code' => ['required', 'string', 'max:50', 'unique:discounts,code'],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'integer', 'min:1'],
            'isActive' => ['nullable', 'boolean'],
            'usageLimit' => ['nullable', 'integer', 'min:1'],
            'perUserLimit' => ['nullable', 'integer', 'min:1'],
            'minOrderAmount' => ['nullable', 'integer', 'min:0'],
            'expiresAt' => ['nullable', 'date'],
            'productIds' => ['nullable', 'array'],
            'productIds.*' => ['integer', 'exists:merch_products,id'],
            'creatorIds' => ['nullable', 'array'],
            'creatorIds.*' => ['integer', 'exists:users,id'],
        ];
    }
}
