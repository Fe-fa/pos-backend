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
        ];
    }

    public function messages(): array
    {
        return [
            'mpesa_receipt.regex' => 'M-Pesa receipt must be 8-12 alphanumeric characters (e.g. QGH7X8Y2K1).',
        ];
    }
}
