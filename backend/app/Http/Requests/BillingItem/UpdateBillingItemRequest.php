<?php

namespace App\Http\Requests\BillingItem;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBillingItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
