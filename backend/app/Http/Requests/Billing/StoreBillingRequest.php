<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class StoreBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,store_id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,customer_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
