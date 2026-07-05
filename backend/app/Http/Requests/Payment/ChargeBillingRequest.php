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
        $hasSplitAllocations = is_array($this->input('payment_allocations'))
            && count($this->input('payment_allocations')) > 0;

        $amountReceivedRule = ($pointsRedeemed > 0 && $amountReceived == 0)
            ? ['required_without:payment_allocations', 'numeric', 'min:0']
            : ['required_without:payment_allocations', 'numeric', 'gt:0'];

        return [
            'payment_method'  => ['required_without:payment_allocations', Rule::in(['cash', 'mpesa', 'card'])],
            'amount_received' => $amountReceivedRule,
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'points_redeemed' => ['nullable', 'integer', 'min:0'],
            'mpesa_phone'     => ['nullable', 'string', 'max:20'],
            'mpesa_code'      => ['nullable', 'string', 'max:50'],
            'card_reference'  => ['nullable', 'string', 'max:100'],
            'card_holder'     => ['nullable', 'string', 'max:100'],

            'payment_allocations' => ['nullable', 'array', 'min:1'],
            'payment_allocations.*.payment_method' => ['required_with:payment_allocations', Rule::in(['cash', 'mpesa', 'card'])],
            'payment_allocations.*.amount_received' => ['required_with:payment_allocations', 'numeric', 'gt:0'],
            'payment_allocations.*.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'payment_allocations.*.mpesa_phone' => ['nullable', 'string', 'max:20'],
            'payment_allocations.*.mpesa_code' => ['nullable', 'string', 'max:50'],
            'payment_allocations.*.mpesa_mode' => ['nullable', Rule::in(['stk', 'manual'])],
            'payment_allocations.*.card_reference' => ['nullable', 'string', 'max:100'],
            'payment_allocations.*.card_holder' => ['nullable', 'string', 'max:100'],
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
