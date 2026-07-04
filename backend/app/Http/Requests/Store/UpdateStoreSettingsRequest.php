<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'settings' => ['required_without:document_sequences', 'array'],
            'document_sequences' => ['required_without:settings', 'array'],

            'settings.default_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.low_stock_alert' => ['nullable', 'integer', 'min:0'],

            'settings.spacious_layout' => ['nullable', 'boolean'],
            'settings.show_product_images' => ['nullable', 'boolean'],

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

            'document_sequences' => ['nullable', 'array'],

            'document_sequences.invoice' => ['nullable', 'array'],
            'document_sequences.invoice.prefix' => ['nullable', 'string', 'max:15'],
            'document_sequences.invoice.suffix' => ['nullable', 'string', 'max:15'],
            'document_sequences.invoice.last_number' => ['nullable', 'integer', 'min:0'],

            'document_sequences.receipt' => ['nullable', 'array'],
            'document_sequences.receipt.prefix' => ['nullable', 'string', 'max:15'],
            'document_sequences.receipt.suffix' => ['nullable', 'string', 'max:15'],
            'document_sequences.receipt.last_number' => ['nullable', 'integer', 'min:0'],
        ];
    }
}