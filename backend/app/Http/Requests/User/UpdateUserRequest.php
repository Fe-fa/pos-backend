<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Support both route-model binding and plain ID
        $userId = $this->route('user')?->user_id ?? $this->route('user');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name'  => ['sometimes', 'required', 'string', 'max:255'],
            'username'   => ['sometimes', 'required', 'string', 'max:255',
                Rule::unique('users', 'username')->ignore($userId, 'user_id'),
            ],
            'email' => ['sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId, 'user_id'),
            ],

            // Phone: unique across all users, but ignore the current user's own record
            'phone' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^\+?[0-9]{10,15}$/',
                Rule::unique('users', 'phone')->ignore($userId, 'user_id'),
            ],

            'password'         => ['nullable', 'string', 'min:8', 'confirmed'],
            'role'             => ['sometimes', 'required', Rule::in([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_CASHIER])],
            'default_store_id' => ['nullable', 'integer', 'exists:stores,store_id'],
            'is_active'        => ['nullable', 'boolean'],
            'store_ids'        => ['nullable', 'array'],
            'store_ids.*'      => ['integer', 'exists:stores,store_id'],

            'shift_name'  => ['nullable', 'string', 'max:100'],
            'shift_start' => ['nullable', 'date_format:H:i', 'required_with:shift_name,shift_end'],
            'shift_end'   => ['nullable', 'date_format:H:i', 'required_with:shift_name,shift_start', 'after:shift_start'],
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
