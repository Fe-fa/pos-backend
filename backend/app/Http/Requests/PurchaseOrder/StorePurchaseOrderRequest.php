<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer', 'exists:stores,store_id'],
            'supplier_id' => ['required', Rule::exists('suppliers', 'supplier_id')->whereNull('deleted_at')],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,product_id'],
            'items.*.product_name_snapshot' => ['nullable', 'string', 'max:255'],
            'items.*.sku_snapshot' => ['nullable', 'string', 'max:100'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }
}
