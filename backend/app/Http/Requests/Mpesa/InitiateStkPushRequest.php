<?php

namespace App\Http\Requests\Mpesa;

use Illuminate\Foundation\Http\FormRequest;

class InitiateStkPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_id' => ['nullable', 'integer', 'exists:billing,billing_id'],
            'grn_id'     => ['nullable', 'integer', 'exists:grns,grn_id'],
            'phone'      => ['required', 'string', 'min:9', 'max:20'],
            'amount'     => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Customer M-Pesa phone number is required.',
            'amount.gt'      => 'Amount must be greater than zero.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (!$this->filled('billing_id') && !$this->filled('grn_id')) {
                $v->errors()->add('billing_id', 'Either billing_id or grn_id is required.');
            }
        });
    }
}
