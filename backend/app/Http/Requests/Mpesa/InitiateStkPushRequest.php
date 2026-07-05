<?php

namespace App\Http\Requests\Mpesa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiateStkPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_id' => ['nullable', 'integer', 'exists:billing,billing_id'],
            'grn_id'     => ['nullable', 'integer', 'exists:grns,grn_id'],
            'phone'      => ['required', 'string', 'min:9', 'max:20'],
            'amount'     => ['nullable', 'numeric', 'gt:0'],
            'points_redeemed' => ['nullable', 'integer', 'min:0'],

            'split_allocations' => ['nullable', 'array', 'min:1'],
            'split_allocations.*.payment_method' => ['required_with:split_allocations', Rule::in(['cash', 'mpesa', 'card'])],
            'split_allocations.*.amount_received' => ['required_with:split_allocations', 'numeric', 'gt:0'],
            'split_allocations.*.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'split_allocations.*.mpesa_phone' => ['nullable', 'string', 'max:20'],
            'split_allocations.*.mpesa_code' => ['nullable', 'string', 'max:50'],
            'split_allocations.*.mpesa_mode' => ['nullable', Rule::in(['stk', 'manual'])],
            'split_allocations.*.card_reference' => ['nullable', 'string', 'max:100'],
            'split_allocations.*.card_holder' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Customer M-Pesa phone number is required.',
            'amount.gt'      => 'Amount must be greater than zero.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (!$this->filled('billing_id') && !$this->filled('grn_id')) {
                $v->errors()->add('billing_id', 'Either billing_id or grn_id is required.');
            }
        });
    }
}
