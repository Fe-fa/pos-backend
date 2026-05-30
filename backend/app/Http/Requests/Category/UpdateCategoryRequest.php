<?php

namespace App\Http\Requests\Category;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->route('category');
        $categoryId = $category instanceof Category ? $category->category_id : $category;
        $storeId = $this->input('store_id') ?: ($category instanceof Category ? $category->store_id : null);

        return [
            'store_id' => ['required', 'exists:stores,store_id'],
            'category_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories', 'category_name')
                    ->where(fn ($q) => $q->where('store_id', $storeId))
                    ->ignore($categoryId, 'category_id'),
            ],
        ];
    }
}
