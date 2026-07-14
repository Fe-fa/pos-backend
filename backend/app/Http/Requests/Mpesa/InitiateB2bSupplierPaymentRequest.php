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

        $phone = trim((string) ($this->input('phone') ?? ''));
        $phoneNumber = trim((string) ($this->input('phone_number') ?? ''));
        $resolvedPhone = $phone !== '' ? $phone : $phoneNumber;

        $this->merge([
            'payment_method' => $paymentMethod,
            'phone' => $resolvedPhone !== '' ? $resolvedPhone : null,
            'phone_number' => $resolvedPhone !== '' ? $resolvedPhone : null,
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
            'phone' => ['nullable', 'required_if:payment_method,mpesa', 'string', 'min:9', 'max:20'],
            'phone_number' => ['nullable', 'string', 'min:9', 'max:20'],
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
            'phone.required_if' => 'The phone field is required when payment method is mpesa.',
        ];
    }
}
