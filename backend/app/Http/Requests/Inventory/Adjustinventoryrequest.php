<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class AdjustInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity'      => ['required', 'integer', 'not_in:0'],   // signed delta, cannot be zero
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'reason'        => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.not_in' => 'Adjustment amount cannot be zero.',
            'quantity.integer' => 'Adjustment amount must be a whole number.',
            'quantity.required' => 'Please enter an adjustment amount.',
        ];
    }
}