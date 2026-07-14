<?php

namespace App\Http\Requests\Grn;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,purchase_order_id'],
            'grn_date' => ['sometimes', 'required', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'invoice_reference_total' => ['nullable', 'numeric', 'min:0'],
            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => [
                'sometimes',
                'required',
                Rule::exists('suppliers', 'supplier_id')->whereNull('deleted_at'),
            ],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'release_to_inventory' => ['nullable', 'boolean'],
            'items' => ['sometimes', 'array'],
            'items.*.grn_item_id' => ['nullable', 'integer'],
            'items.*.po_item_id' => ['nullable', 'integer', 'exists:purchase_order_items,purchase_order_item_id'],
            'items.*.product_id' => ['required_with:items', 'exists:products,product_id'],
            'items.*.product_name_snapshot' => ['nullable', 'string', 'max:255'],
            'items.*.barcode' => ['nullable', 'string', 'max:100'],
            'items.*.batch_no' => ['nullable', 'string', 'max:100'],
            'items.*.quantity_expected' => ['required_with:items', 'integer', 'min:1'],
            'items.*.quantity_accepted' => ['required_with:items', 'integer', 'min:0'],
            'items.*.quantity_rejected' => ['nullable', 'integer', 'min:0'],
            'items.*.free_qty' => ['nullable', 'integer', 'min:0'],
            'items.*.cost_price_excl_tax' => ['nullable', 'numeric', 'min:0'],
            'items.*.cost_price_incl_tax' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.taxable_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.low_inventory_level' => ['nullable', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }
}
