<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

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
            'payment.method' => ['required', 'in:cash,mpesa,card'],
            'payment.amount_received' => ['nullable', 'numeric', 'min:0'],
            'payment.amount_tendered' => ['required', 'numeric', 'min:0'],
            'payment.points_redeemed' => ['nullable', 'integer', 'min:0'],
            'payment.mpesa_phone' => ['nullable', 'string', 'max:50'],
            'payment.mpesa_code' => ['nullable', 'string', 'max:100'],
            'payment.card_reference' => ['nullable', 'string', 'max:100'],
            'payment.card_holder' => ['nullable', 'string', 'max:100'],
        ];
    }
}
