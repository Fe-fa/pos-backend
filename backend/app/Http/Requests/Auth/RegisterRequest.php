<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'last_name'  => ['required', 'string', 'max:50'],
            'username'   => ['required', 'string', 'max:50', 'unique:users,username'],

            'email' => [
                'required',
                'string',
                'email:rfc,dns',   // checks format AND that the domain actually has valid MX/DNS records
                'max:100',
                'unique:users,email',
            ],

            'phone' => [
                'nullable',
                'string',
                'regex:/^\+?[0-9]{10,15}$/',
                'unique:users,phone',
            ],

            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->mixedCase()   // at least one upper + one lower
                    ->numbers()     // at least one digit
                    ->symbols()     // at least one special character
                    ->uncompromised(), // checks against known-breached password lists (haveibeenpwned)
            ],

            'default_store_id' => ['nullable', 'integer', 'exists:stores,store_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.unique' => 'This phone number is already registered.',
            'phone.regex'  => 'Please enter a valid phone number (10–15 digits, optional leading +).',
            'email.email'  => 'Please enter a valid, deliverable email address.',
        ];
    }
}