<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'settings' => ['nullable', 'array'],
            'document_sequences' => ['nullable', 'array'],
            'mpesa' => ['nullable', 'array'],

            'settings.default_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.low_stock_alert' => ['nullable', 'integer', 'min:0'],
            'settings.spacious_layout' => ['nullable', 'boolean'],
            'settings.show_product_images' => ['nullable', 'boolean'],
            'settings.receipt_layout' => ['nullable', Rule::in(['default', 'compact', 'detailed'])],

            'settings.receipt_header' => ['nullable', 'string', 'max:2000'],
            'settings.invoice_header' => ['nullable', 'string', 'max:2000'],
            'settings.receipt_footer' => ['nullable', 'string', 'max:2000'],
            'settings.invoice_footer' => ['nullable', 'string', 'max:2000'],

            'settings.show_barcode' => ['nullable', 'boolean'],
            'settings.show_qrcode' => ['nullable', 'boolean'],
            'settings.show_vat_summary' => ['nullable', 'boolean'],
            'settings.show_customer_on_print' => ['nullable', 'boolean'],
            'settings.show_cashier_on_print' => ['nullable', 'boolean'],
            'settings.show_logo_on_print' => ['nullable', 'boolean'],
            'settings.show_store_contacts_on_print' => ['nullable', 'boolean'],
            'settings.show_store_pin_on_print' => ['nullable', 'boolean'],
            'settings.show_payment_method_on_print' => ['nullable', 'boolean'],

            'settings.paper_width' => ['nullable', 'integer', 'in:58,80'],
            'settings.print_delay_ms' => ['nullable', 'integer', 'min:0', 'max:3000'],

            'document_sequences.receipt' => ['nullable', 'array'],
            'document_sequences.receipt.prefix' => ['nullable', 'string', 'max:15'],
            'document_sequences.receipt.suffix' => ['nullable', 'string', 'max:15'],
            'document_sequences.receipt.last_number' => ['nullable', 'integer', 'min:0'],

            'document_sequences.invoice' => ['nullable', 'array'],
            'document_sequences.invoice.prefix' => ['nullable', 'string', 'max:15'],
            'document_sequences.invoice.suffix' => ['nullable', 'string', 'max:15'],
            'document_sequences.invoice.last_number' => ['nullable', 'integer', 'min:0'],

            'document_sequences.order' => ['nullable', 'array'],
            'document_sequences.order.prefix' => ['nullable', 'string', 'max:15'],
            'document_sequences.order.suffix' => ['nullable', 'string', 'max:15'],
            'document_sequences.order.last_number' => ['nullable', 'integer', 'min:0'],

            'document_sequences.packing_slip' => ['nullable', 'array'],
            'document_sequences.packing_slip.prefix' => ['nullable', 'string', 'max:15'],
            'document_sequences.packing_slip.suffix' => ['nullable', 'string', 'max:15'],
            'document_sequences.packing_slip.last_number' => ['nullable', 'integer', 'min:0'],

            'mpesa.enabled' => ['nullable', 'boolean'],
            'mpesa.environment' => ['nullable', Rule::in(['sandbox', 'production'])],
            'mpesa.shortcode_type' => ['nullable', Rule::in(['paybill', 'till'])],
            'mpesa.shortcode' => ['nullable', 'string', 'max:20'],
            'mpesa.till_number' => ['nullable', 'string', 'max:20'],
            'mpesa.consumer_key' => ['nullable', 'string', 'max:2048'],
            'mpesa.consumer_secret' => ['nullable', 'string', 'max:2048'],
            'mpesa.passkey' => ['nullable', 'string', 'max:4096'],
            'mpesa.callback_base_url' => ['nullable', 'url', 'max:255'],
            'mpesa.account_reference_prefix' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasSettings = is_array($this->input('settings')) && count($this->input('settings')) > 0;
            $hasSequences = is_array($this->input('document_sequences')) && count($this->input('document_sequences')) > 0;
            $hasMpesa = is_array($this->input('mpesa')) && count($this->input('mpesa')) > 0;

            if (! $hasSettings && ! $hasSequences && ! $hasMpesa) {
                $validator->errors()->add('settings', 'At least one settings section must be provided.');
            }
        });
    }
}
