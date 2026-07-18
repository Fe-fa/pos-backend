<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,store_id'],
            'cashier_user_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'transaction_date' => ['nullable', 'date'],
            'method' => ['required', Rule::in(['cash', 'mpesa'])],
            'category' => ['required', Rule::in([
                'petty_cash',
                'utilities',
                'transport',
                'maintenance',
                'rent',
                'payroll_advance',
                'bank_charges',
                'tax',
                'other',
            ])],
            'entity_name' => ['required', 'string', 'max:180'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
