<?php

namespace App\Http\Requests\Waitlist;

use Illuminate\Foundation\Http\FormRequest;

class StoreWaitlistRequest extends FormRequest
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
            'firstName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:waitlist_entries,email'],
            'phone' => ['nullable', 'string', 'max:25'],
            'country' => ['required', 'string', 'max:120'],
            'describes' => ['required', 'string', 'max:255'],
            'loveToSee' => ['nullable', 'string', 'max:1000'],
            'agreed' => ['accepted'],
        ];
    }
}