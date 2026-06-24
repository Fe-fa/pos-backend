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
        $pointsRedeemed = (int) $this->input('points_redeemed', 0);
        $amountReceived = (float) $this->input('amount_received', 0);

        // When points are being redeemed and the cashier sends 0,
        // the PaymentService will verify points fully cover the bill.
        // Any other case still requires a positive amount.
        $amountReceivedRule = ($pointsRedeemed > 0 && $amountReceived == 0)
            ? ['required', 'numeric', 'min:0']
            : ['required', 'numeric', 'gt:0'];

        return [
            'payment_method'  => ['required', Rule::in(['cash', 'mpesa', 'card'])],
            'amount_received' => $amountReceivedRule,
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'points_redeemed' => ['nullable', 'integer', 'min:0'],
            'mpesa_phone'     => ['nullable', 'string', 'max:20'],
            'mpesa_code'      => ['nullable', 'string', 'max:50'],
            'card_reference'  => ['nullable', 'string', 'max:100'],
            'card_holder'     => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_received.gt'  => 'Amount received must be greater than zero.',
            'amount_received.min' => 'Amount received cannot be negative.',
            'amount_tendered.min' => 'Amount tendered cannot be negative.',
        ];
    }
}