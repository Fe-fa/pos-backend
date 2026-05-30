<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_ids' => ['required', 'array'],
            'store_ids.*' => ['integer', 'exists:stores,store_id'],
        ];
    }
}
