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
            'product_id' => ['required', 'exists:products,product_id'],
            'product_name_snapshot' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'brand_name' => ['nullable', 'string', 'max:150'],
            'item_type' => ['nullable', 'string', 'max:50'],
            'batch_no' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
            'qty_received' => ['required', 'integer', 'min:1'],
            'free_qty' => ['nullable', 'integer', 'min:0'],
            'cost_price_excl_tax' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0'],
            'cess_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_type' => ['nullable', 'string', 'max:50'],
            'hsn_code' => ['nullable', 'string', 'max:50'],
            'prod_code' => ['nullable', 'string', 'max:100'],
            'cost_price_incl_tax' => ['nullable', 'numeric', 'min:0'],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'scheme_discount_percent' => ['nullable', 'numeric', 'min:0'],
            'scheme_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'key_discount_percent' => ['nullable', 'numeric', 'min:0'],
            'key_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'cash_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'total_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'taxable_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'low_inventory_level' => ['nullable', 'integer', 'min:0'],
            'category_name' => ['nullable', 'string', 'max:120'],
            'subcategory_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
