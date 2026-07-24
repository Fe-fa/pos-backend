<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_name' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:10'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'telephone' => ['nullable', 'string', 'max:50'],
            'pin' => ['nullable', 'string', 'max:50'],
            'physical_address' => ['nullable', 'string', 'max:1000'],
            'email_address' => ['nullable', 'email', 'max:255', 'unique:stores,email_address'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
