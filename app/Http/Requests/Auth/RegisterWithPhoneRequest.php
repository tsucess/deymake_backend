<?php

namespace App\Http\Requests\Auth;

use App\Support\Username;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterWithPhoneRequest extends FormRequest
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
            'fullName' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'regex:'.Username::VALIDATION_REGEX, 'unique:users,username'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,20}$/', 'unique:users,phone'],
            'countryCode' => ['nullable', 'string', 'max:8'],
            'code' => ['required', 'string', 'size:4'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()],
            'dateOfBirth' => ['nullable', 'date', 'before:today'],
        ];
    }
}
