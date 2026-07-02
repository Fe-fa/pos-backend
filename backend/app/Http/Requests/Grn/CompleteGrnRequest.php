<?php

namespace App\Http\Requests\Grn;

use Illuminate\Foundation\Http\FormRequest;

class CompleteGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string'],
            'release_to_inventory' => ['nullable', 'boolean'],
        ];
    }
}
