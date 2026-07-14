<?php

namespace App\Http\Requests\PaymentVoucher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'voucher_date' => ['sometimes', 'required', 'date'],
            'delivery_note_no' => ['nullable', 'string', 'max:100'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'payee_name' => ['sometimes', 'required', 'string', 'max:255'],
            'payee_address' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['sometimes', 'required', Rule::in(['cash', 'mpesa', 'card', 'bank_transfer', 'cheque'])],
            'payment_account' => ['nullable', 'string', 'max:120'],
            'cheque_no' => ['nullable', 'string', 'max:120'],
            'cheque_date' => ['nullable', 'date'],
            'authorized_by' => ['nullable', 'string', 'max:150'],
            'authorized_signature' => ['nullable', 'string', 'max:150'],
            'authorized_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'override_note' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['draft', 'pending_approval', 'override_required', 'authorized', 'partial', 'partially_paid', 'processing', 'paid', 'cancelled'])],
        ];
    }
}
