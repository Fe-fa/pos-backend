<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product?->product_id ?? $product;
        $storeId = $this->input('store_id') ?: ($product?->store_id ?? null);

        return [
            'store_id'      => ['required', 'exists:stores,store_id'],
            'category_id'   => ['sometimes', 'required', 'exists:categories,category_id'],
            'sku'           => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('products', 'sku')
                    ->where(fn ($q) => $q->where('store_id', $storeId))
                    ->ignore($productId, 'product_id'),
            ],
            'product_name'  => ['sometimes', 'required', 'string', 'max:255'],
            'price'         => ['sometimes', 'required', 'numeric', 'min:0'],
            'cost_price'    => ['sometimes', 'required', 'numeric', 'min:0'],
            'vat_rate'      => ['nullable', 'numeric', 'min:0'],
            'image'         => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'image_url'     => ['nullable', 'url', 'max:2048'],
            'clear_image'   => ['nullable', 'boolean'],
            'is_active'     => ['sometimes', 'required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        if ($this->has('clear_image')) {
            $this->merge([
                'clear_image' => filter_var($this->input('clear_image'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }
}
