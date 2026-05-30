<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'exists:stores,store_id'],
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/'],
            'current_balance' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
