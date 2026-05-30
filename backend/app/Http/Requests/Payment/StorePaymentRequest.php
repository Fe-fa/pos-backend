<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_id' => ['required', 'integer', 'exists:billing,billing_id'],
            'amount_received' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['nullable', 'date'],
        ];
    }
}
