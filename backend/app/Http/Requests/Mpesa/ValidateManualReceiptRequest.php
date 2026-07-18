<?php

namespace App\Http\Requests\Mpesa;

use Illuminate\Foundation\Http\FormRequest;

class ValidateManualReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_id'    => ['required', 'integer', 'exists:billing,billing_id'],
            'mpesa_receipt' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8,12}$/'],
            'amount'        => ['required', 'numeric', 'gt:0'],
            'phone'         => ['nullable', 'string', 'max:20'],
            'points_redeemed' => ['nullable', 'integer', 'min:0'],

            'split_allocations' => ['nullable', 'array', 'min:1'],
            'split_allocations.*.payment_method' => ['required_with:split_allocations', 'string'],
            'split_allocations.*.amount_received' => ['required_with:split_allocations', 'numeric', 'gt:0'],
            'split_allocations.*.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'split_allocations.*.mpesa_phone' => ['nullable', 'string', 'max:30'],
            'split_allocations.*.mpesa_code' => ['nullable', 'string', 'max:50'],
            'split_allocations.*.mpesa_mode' => ['nullable', 'string', 'max:20'],
            'split_allocations.*.card_reference' => ['nullable', 'string', 'max:100'],
            'split_allocations.*.card_holder' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'mpesa_receipt.regex' => 'M-Pesa receipt must be 8-12 alphanumeric characters (e.g. QGH7X8Y2K1).',
        ];
    }
}
