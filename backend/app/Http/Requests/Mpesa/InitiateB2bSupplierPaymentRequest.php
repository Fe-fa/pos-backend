<?php

namespace App\Http\Requests\Mpesa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiateB2bSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $paymentMethod = strtolower(trim((string) $this->input('payment_method', '')));
        $paymentMethod = $paymentMethod === 'm-pesa' ? 'mpesa' : $paymentMethod;

        $receiverType = strtolower(trim((string) $this->input('receiver_type', 'mobile_wallet')));
        $receiverType = match ($receiverType) {
            'phone', 'mobile', 'mobile_money', 'mobile-money', 'msisdn' => 'mobile_wallet',
            'buygoods', 'buy_goods', 'till_number', 'business_buy_goods' => 'till',
            'shortcode', 'paybill_number', 'business', 'business_shortcode' => 'paybill',
            default => $receiverType,
        };

        $phone = trim((string) ($this->input('phone') ?? ''));
        $phoneNumber = trim((string) ($this->input('phone_number') ?? ''));
        $resolvedPhone = $phone !== '' ? $phone : $phoneNumber;

        $recipientShortcode = trim((string) (
            $this->input('recipient_shortcode')
            ?? $this->input('business_shortcode')
            ?? $this->input('shortcode')
            ?? $this->input('till_number')
            ?? $this->input('paybill_number')
            ?? ''
        ));

        $this->merge([
            'payment_method' => $paymentMethod,
            'receiver_type' => $paymentMethod === 'mpesa' ? $receiverType : null,
            'phone' => $resolvedPhone !== '' ? $resolvedPhone : null,
            'phone_number' => $resolvedPhone !== '' ? $resolvedPhone : null,
            'recipient_shortcode' => $recipientShortcode !== '' ? preg_replace('/\D+/', '', $recipientShortcode) : null,
            'account_reference' => trim((string) ($this->input('account_reference') ?? '')) ?: null,
            'remarks' => trim((string) ($this->input('remarks') ?? '')) ?: null,
            'occasion' => trim((string) ($this->input('occasion') ?? '')) ?: null,
            'supporting_document_name' => trim((string) ($this->input('supporting_document_name') ?? '')) ?: null,
        ]);
    }

public function rules(): array
{
    return [
        'voucher_id' => ['required', 'integer', 'exists:payment_vouchers,payment_voucher_id'],
        'amount' => ['required', 'numeric', 'gt:0'],
        'payment_method' => ['required', Rule::in(['cash', 'mpesa'])],

        'receiver_type' => [
            'nullable',
            Rule::requiredIf(fn () => $this->input('payment_method') === 'mpesa'),
            Rule::in(['mobile_wallet', 'till', 'paybill']),
        ],

        'phone' => [
            'nullable',
            Rule::requiredIf(fn () =>
                $this->input('payment_method') === 'mpesa'
                && $this->input('receiver_type') === 'mobile_wallet'
            ),
            'string', 'min:9', 'max:20',
        ],
        'phone_number' => ['nullable', 'string', 'min:9', 'max:20'],

        'recipient_shortcode' => [
            'nullable',
            Rule::requiredIf(fn () =>
                $this->input('payment_method') === 'mpesa'
                && in_array($this->input('receiver_type'), ['till', 'paybill'], true)
            ),
            'string', 'min:5', 'max:20',
        ],

        'account_reference' => ['nullable', 'string', 'max:100'],
        'remarks' => ['nullable', 'string', 'max:255'],
        'occasion' => ['nullable', 'string', 'max:100'],
        'transaction_fee' => ['nullable', 'numeric', 'min:0'],
        'supporting_document_name' => ['nullable', 'string', 'max:255'],
    ];
}

    public function messages(): array
    {
        return [
            'phone.required_if' => 'The phone field is required for M-Pesa phone payouts.',
            'recipient_shortcode.required_unless' => 'Till / PayBill payouts require a target shortcode.',
        ];
    }
}
