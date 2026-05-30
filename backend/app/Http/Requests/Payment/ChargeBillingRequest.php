<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChargeBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['cash', 'mpesa', 'card'])],
            'amount_received' => ['required', 'numeric', 'gt:0'],
            'amount_tendered' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
