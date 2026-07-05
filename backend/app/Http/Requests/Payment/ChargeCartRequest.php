<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChargeCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,store_id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,customer_id'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,product_id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'payment' => ['required', 'array'],
            'payment.method' => ['required_without:payment.allocations', Rule::in(['cash', 'mpesa', 'card'])],
            'payment.amount_received' => ['nullable', 'numeric', 'min:0'],
            'payment.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'payment.points_redeemed' => ['nullable', 'integer', 'min:0'],
            'payment.mpesa_phone' => ['nullable', 'string', 'max:50'],
            'payment.mpesa_code' => ['nullable', 'string', 'max:100'],
            'payment.card_reference' => ['nullable', 'string', 'max:100'],
            'payment.card_holder' => ['nullable', 'string', 'max:100'],

            'payment.allocations' => ['nullable', 'array', 'min:1'],
            'payment.allocations.*.payment_method' => ['required_with:payment.allocations', Rule::in(['cash', 'mpesa', 'card'])],
            'payment.allocations.*.amount_received' => ['required_with:payment.allocations', 'numeric', 'gt:0'],
            'payment.allocations.*.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'payment.allocations.*.mpesa_phone' => ['nullable', 'string', 'max:20'],
            'payment.allocations.*.mpesa_code' => ['nullable', 'string', 'max:50'],
            'payment.allocations.*.mpesa_mode' => ['nullable', Rule::in(['stk', 'manual'])],
            'payment.allocations.*.card_reference' => ['nullable', 'string', 'max:100'],
            'payment.allocations.*.card_holder' => ['nullable', 'string', 'max:100'],
        ];
    }
}
