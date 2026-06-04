<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_CASHIER])],
            'default_store_id' => ['nullable', 'integer', 'exists:stores,store_id'],
            'is_active' => ['nullable', 'boolean'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['integer', 'exists:stores,store_id'],

            'shift_name' => ['nullable', 'string', 'max:100'],
            'shift_start' => ['nullable', 'date_format:H:i', 'required_with:shift_name,shift_end'],
            'shift_end' => ['nullable', 'date_format:H:i', 'required_with:shift_name,shift_start', 'after:shift_start'],
        ];
    }
}
