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
            'payment_method'  => ['required', Rule::in(['cash', 'mpesa', 'card'])],
            'amount_received' => ['required', 'numeric', 'gt:0'],
            'amount_tendered' => ['nullable', 'numeric', 'gt:0'],
            'points_redeemed' => ['nullable', 'integer', 'min:0'],  // ← ADD
            'mpesa_phone'     => ['nullable', 'string', 'max:20'],  // ← ADD
            'mpesa_code'      => ['nullable', 'string', 'max:50'],  // ← ADD
            'card_reference'  => ['nullable', 'string', 'max:100'], // ← ADD
            'card_holder'     => ['nullable', 'string', 'max:100'], // ← ADD
        ];
    }
}
