<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id'      => ['sometimes', 'required', 'exists:stores,store_id'],
            'product_id'    => ['sometimes', 'required', 'exists:products,product_id'],
            'batch_no'      => ['nullable', 'string', 'max:100'],
            'quantity'      => ['sometimes', 'required', 'integer', 'min:1'], // 'sometimes' — edit mode omits it entirely
            'reorder_level' => ['nullable', 'integer', 'min:0'],
        ];
    }
}