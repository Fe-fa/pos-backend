<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_received' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['nullable', 'date'],
        ];
    }
}
