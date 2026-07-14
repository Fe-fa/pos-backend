<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'required', Rule::exists('suppliers', 'supplier_id')->whereNull('deleted_at')],
            'order_date' => ['sometimes', 'required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,product_id'],
            'items.*.product_name_snapshot' => ['nullable', 'string', 'max:255'],
            'items.*.sku_snapshot' => ['nullable', 'string', 'max:100'],
            'items.*.quantity_ordered' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }
}
