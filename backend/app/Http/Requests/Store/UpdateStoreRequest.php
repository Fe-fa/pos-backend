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
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'telephone' => ['nullable', 'string', 'max:50'],
            'pin' => ['nullable', 'string', 'max:50'],
            'physical_address' => ['nullable', 'string', 'max:1000'],
            'email_address' => ['nullable', 'email', 'max:255', Rule::unique('stores', 'email_address')->ignore($storeId, 'store_id')],
            'is_active' => ['nullable', 'boolean'],

            'mpesa_enabled' => ['sometimes', 'boolean'],
            'mpesa_environment' => ['nullable', Rule::in(['sandbox', 'production'])],
            'mpesa_shortcode_type' => ['nullable', Rule::in(['paybill', 'till'])],
            'mpesa_shortcode' => ['nullable', 'string', 'max:20'],
            'mpesa_till_number' => ['nullable', 'string', 'max:20'],
            'mpesa_consumer_key' => ['nullable', 'string', 'max:500'],
            'mpesa_consumer_secret' => ['nullable', 'string', 'max:500'],
            'mpesa_passkey' => ['nullable', 'string', 'max:500'],
            'mpesa_callback_base_url' => ['nullable', 'url', 'max:255'],
            'mpesa_account_reference_prefix' => ['nullable', 'string', 'max:20'],
        ];
    }
}
