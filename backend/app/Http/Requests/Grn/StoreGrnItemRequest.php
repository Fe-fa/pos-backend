<?php

namespace App\Http\Requests\Grn;

use Illuminate\Foundation\Http\FormRequest;

class StoreGrnItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'po_item_id' => ['nullable', 'integer', 'exists:purchase_order_items,purchase_order_item_id'],
            'product_id' => ['required', 'exists:products,product_id'],
            'product_name_snapshot' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'batch_no' => ['nullable', 'string', 'max:100'],
            'quantity_expected' => ['required', 'integer', 'min:1'],
            'qty_received' => ['nullable', 'integer', 'min:0'],
            'quantity_accepted' => ['required', 'integer', 'min:0'],
            'quantity_rejected' => ['nullable', 'integer', 'min:0'],
            'free_qty' => ['nullable', 'integer', 'min:0'],
            'cost_price_excl_tax' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0'],
            'cost_price_incl_tax' => ['nullable', 'numeric', 'min:0'],
            'taxable_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'low_inventory_level' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
