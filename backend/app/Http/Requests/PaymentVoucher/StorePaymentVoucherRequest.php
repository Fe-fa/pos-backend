<?php

namespace App\Http\Requests\PaymentVoucher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,store_id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,supplier_id'],
            'grn_id' => ['required', 'integer', 'exists:grns,grn_id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,purchase_order_id'],
            'voucher_date' => ['required', 'date'],
            'delivery_note_no' => ['nullable', 'string', 'max:100'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'payee_name' => ['required', 'string', 'max:255'],
            'payee_address' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['required', Rule::in(['cash', 'mpesa', 'card', 'bank_transfer', 'cheque'])],
            'payment_account' => ['nullable', 'string', 'max:120'],
            'cheque_no' => ['nullable', 'string', 'max:120'],
            'cheque_date' => ['nullable', 'date'],
            'authorized_by' => ['nullable', 'string', 'max:150'],
            'authorized_signature' => ['nullable', 'string', 'max:150'],
            'authorized_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['draft', 'pending_approval'])],
        ];
    }
}
