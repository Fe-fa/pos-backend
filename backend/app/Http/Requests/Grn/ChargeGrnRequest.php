<?php

namespace App\Http\Requests\Grn;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChargeGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_voucher_id' => ['required', 'integer', 'exists:payment_vouchers,payment_voucher_id'],
            'payment_method' => ['required', Rule::in(['cash', 'mpesa', 'card', 'bank_transfer', 'cheque'])],
            'amount_received' => ['required', 'numeric', 'min:0.01'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'mpesa_phone' => ['nullable', 'required_if:payment_method,mpesa', 'string', 'max:30'],
            'mpesa_code' => ['nullable', 'required_if:payment_method,mpesa', 'string', 'max:100'],
            'card_reference' => ['nullable', 'required_if:payment_method,card', 'string', 'max:100'],
            'card_holder' => ['nullable', 'required_if:payment_method,card', 'string', 'max:150'],
            'bank_reference' => ['nullable', 'required_if:payment_method,bank_transfer,cheque', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('payment_method') !== 'cash') {
                return;
            }

            $amountReceived = (float) $this->input('amount_received', 0);
            $amountTendered = (float) $this->input('amount_tendered', $amountReceived);

            if ($amountTendered < $amountReceived) {
                $validator->errors()->add('amount_tendered', 'Cash tendered must be greater than or equal to the amount received.');
            }
        });
    }
}
