<?php

namespace App\Http\Requests\Discount;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:180'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('discounts', 'code')->ignore($this->route('discount')?->id)],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'value' => ['sometimes', 'integer', 'min:1'],
            'isActive' => ['sometimes', 'boolean'],
            'usageLimit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'perUserLimit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'minOrderAmount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'expiresAt' => ['sometimes', 'nullable', 'date'],
            'productIds' => ['sometimes', 'nullable', 'array'],
            'productIds.*' => ['integer', 'exists:merch_products,id'],
            'creatorIds' => ['sometimes', 'nullable', 'array'],
            'creatorIds.*' => ['integer', 'exists:users,id'],
        ];
    }
}
