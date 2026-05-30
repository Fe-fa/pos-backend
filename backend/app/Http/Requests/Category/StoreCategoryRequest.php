<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => ['required', 'exists:stores,store_id'],
            'category_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories', 'category_name')
                    ->where(fn ($q) => $q->where('store_id', $this->input('store_id'))),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'category_name.required' => 'Category name is required.',
            'category_name.unique' => 'This category already exists in the selected store.',
        ];
    }
}
