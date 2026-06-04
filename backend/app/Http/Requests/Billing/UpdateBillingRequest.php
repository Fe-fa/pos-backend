<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,customer_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'fulfillment_status' => [
                'nullable',
                Rule::in(['pending', 'processing', 'shipped', 'delivered']),
            ],
            'fulfillment_type' => [
                'nullable',
                Rule::in(['walk_in_counter', 'delivery']),
            ],
        ];
    }
}
