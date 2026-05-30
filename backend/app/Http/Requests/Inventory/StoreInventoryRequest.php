<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id'      => ['required', 'exists:stores,store_id'],
            'product_id'    => ['required', 'exists:products,product_id'],
            'batch_no'      => ['nullable', 'string', 'max:100'],
            'quantity'      => ['required', 'integer', 'min:1'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
