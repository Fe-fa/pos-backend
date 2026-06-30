<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

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
            'email'      => ['required', 'string', 'email', 'max:100', 'unique:users,email'],

            // Phone: optional but unique when provided, and format-validated
            'phone'      => [
                'nullable',
                'string',
                'regex:/^\+?[0-9]{10,15}$/',
                'unique:users,phone',
            ],

            'password'         => ['required', 'string', 'min:8', 'confirmed'],
            'default_store_id' => ['nullable', 'integer', 'exists:stores,store_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.unique' => 'This phone number is already registered.',
            'phone.regex'  => 'Please enter a valid phone number (10–15 digits, optional leading +).',
        ];
    }
}
