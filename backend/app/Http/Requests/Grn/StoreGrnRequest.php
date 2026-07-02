<?php

namespace App\Http\Requests\Grn;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'exists:stores,store_id'],
            'grn_date' => ['required', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'supplier_id' => [
                'required',
                Rule::exists('suppliers', 'supplier_id')->whereNull('deleted_at'),
            ],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'is_po_available' => ['nullable', 'boolean'],
            'po_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'additional_discount_1' => ['nullable', 'numeric', 'min:0'],
            'additional_discount_2' => ['nullable', 'numeric', 'min:0'],
            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'round_off' => ['nullable', 'numeric'],
            'release_to_inventory' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Please choose a supplier from the supplier master.',
            'supplier_id.exists' => 'The selected supplier is invalid or has been removed.',
        ];
    }
}
