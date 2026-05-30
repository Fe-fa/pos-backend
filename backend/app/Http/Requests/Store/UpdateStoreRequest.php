<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->route('store')?->store_id ?? $this->route('store');

        return [
            'store_name' => ['sometimes', 'required', 'string', 'max:255'],
            'location' => ['sometimes', 'required', 'string', 'max:255'],
            'currency' => ['sometimes', 'required', 'string', 'max:10'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'telephone' => ['nullable', 'string', 'max:50'],
            'pin' => ['nullable', 'string', 'max:50'],
            'physical_address' => ['nullable', 'string', 'max:1000'],
            'email_address' => ['nullable', 'email', 'max:255', Rule::unique('stores', 'email_address')->ignore($storeId, 'store_id')],
            'is_active' => ['nullable', 'boolean'],
            
        ];
    }
}
