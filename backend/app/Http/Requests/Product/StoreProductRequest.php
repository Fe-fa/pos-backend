<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id'      => ['required', 'exists:stores,store_id'],
            'category_id'   => ['required', 'exists:categories,category_id'],
            'sku'           => [
                'required',
                'string',
                'max:50',
                Rule::unique('products', 'sku')
                    ->where(fn ($q) => $q->where('store_id', $this->input('store_id'))),
            ],
            'product_name'  => ['required', 'string', 'max:255'],
            'price'         => ['required', 'numeric', 'min:0'],
            'cost_price'    => ['required', 'numeric', 'min:0'],
            'vat_rate'      => ['nullable', 'numeric', 'min:0'],
            'image'         => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'image_url'     => ['nullable', 'url', 'max:2048'],
            'clear_image'   => ['nullable', 'boolean'],
            'is_active'     => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active'   => filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'clear_image' => filter_var($this->input('clear_image'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
        ]);
    }
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
{
    // Log the actual input for debugging
    \Log::error('Validation Failed:', [
        'errors' => $validator->errors()->toArray(),
        'all_input' => $this->all(),
        'files' => $this->allFiles() // This shows exactly what files were detected
    ]);

    throw new \Illuminate\Http\Exceptions\HttpResponseException(
        response()->json(['errors' => $validator->errors()], 422)
    );
}
}
