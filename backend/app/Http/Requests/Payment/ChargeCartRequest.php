<?php

namespace App\Http\Requests\Payment;

use App\Support\ChequeBankDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChargeCartRequest extends FormRequest
{
    // Cheque/leaf numbers are printed on the physical cheque and vary by bank/book —
    // letters, digits, and dashes are all valid (e.g. "004821", "CHQ-004821").
    private const CHEQUE_NUMBER_REGEX = '/^[A-Z0-9\-]{1,50}$/i';

    // Bank account numbers are numeric-only for the banks in our directory.
    private const ACCOUNT_NUMBER_REGEX = '/^[0-9]{6,20}$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bankCodes = collect(ChequeBankDirectory::all())->pluck('code')->all();

        return [
            'store_id' => ['required', 'integer', 'exists:stores,store_id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,customer_id'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,product_id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'payment' => ['required', 'array'],
            'payment.method' => ['required_without:payment.allocations', Rule::in(['cash', 'mpesa', 'card', 'cheque'])],
            'payment.amount_received' => ['nullable', 'numeric', 'min:0'],
            'payment.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'payment.points_redeemed' => ['nullable', 'integer', 'min:0'],
            'payment.mpesa_phone' => ['nullable', 'string', 'max:50'],
            'payment.mpesa_code' => ['nullable', 'string', 'max:100'],
            'payment.mpesa_mode' => ['nullable', Rule::in(['stk', 'manual', 'till'])],
            'payment.card_reference' => ['nullable', 'string', 'max:100'],
            'payment.card_holder' => ['nullable', 'string', 'max:100'],
            'payment.cheque_bank_name' => ['nullable', 'string', 'max:120'],
            'payment.cheque_bank_code' => ['nullable', 'string', 'max:30', Rule::in($bankCodes)],
            'payment.cheque_number' => ['nullable', 'string', 'max:50', 'regex:' . self::CHEQUE_NUMBER_REGEX],
            'payment.cheque_date' => ['nullable', 'date'],
            'payment.cheque_account_name' => ['nullable', 'string', 'max:120'],
            'payment.cheque_account_number' => ['nullable', 'string', 'max:50', 'regex:' . self::ACCOUNT_NUMBER_REGEX],
            'payment.cheque_branch_name' => ['nullable', 'string', 'max:120'],
            'payment.cheque_notes' => ['nullable', 'string', 'max:2000'],

            'payment.allocations' => ['nullable', 'array', 'min:1'],
            'payment.allocations.*.payment_method' => ['required_with:payment.allocations', Rule::in(['cash', 'mpesa', 'card', 'cheque'])],
            'payment.allocations.*.amount_received' => ['required_with:payment.allocations', 'numeric', 'gt:0'],
            'payment.allocations.*.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'payment.allocations.*.mpesa_phone' => ['nullable', 'string', 'max:20'],
            'payment.allocations.*.mpesa_code' => ['nullable', 'string', 'max:50'],
            'payment.allocations.*.mpesa_mode' => ['nullable', Rule::in(['stk', 'manual', 'till'])],
            'payment.allocations.*.card_reference' => ['nullable', 'string', 'max:100'],
            'payment.allocations.*.card_holder' => ['nullable', 'string', 'max:100'],
            'payment.allocations.*.cheque_bank_name' => ['nullable', 'string', 'max:120'],
            'payment.allocations.*.cheque_bank_code' => ['nullable', 'string', 'max:30', Rule::in($bankCodes)],
            'payment.allocations.*.cheque_number' => ['nullable', 'string', 'max:50', 'regex:' . self::CHEQUE_NUMBER_REGEX],
            'payment.allocations.*.cheque_date' => ['nullable', 'date'],
            'payment.allocations.*.cheque_account_name' => ['nullable', 'string', 'max:120'],
            'payment.allocations.*.cheque_account_number' => ['nullable', 'string', 'max:50', 'regex:' . self::ACCOUNT_NUMBER_REGEX],
            'payment.allocations.*.cheque_branch_name' => ['nullable', 'string', 'max:120'],
            'payment.allocations.*.cheque_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $payment = (array) $this->input('payment', []);

            $this->validateChequeBankChoice(
                $validator,
                [
                    'payment_method' => $payment['method'] ?? null,
                    'cheque_bank_name' => $payment['cheque_bank_name'] ?? null,
                    'cheque_bank_code' => $payment['cheque_bank_code'] ?? null,
                ],
                'payment.'
            );

            foreach ((array) ($payment['allocations'] ?? []) as $index => $row) {
                $this->validateChequeBankChoice($validator, (array) $row, "payment.allocations.{$index}.");
            }
        });
    }

    private function validateChequeBankChoice($validator, array $row, string $prefix): void
    {
        if (strtolower(trim((string) ($row['payment_method'] ?? ''))) !== 'cheque') {
            return;
        }

        $bankName = trim((string) ($row['cheque_bank_name'] ?? ''));
        $bankCode = trim((string) ($row['cheque_bank_code'] ?? ''));

        if ($bankName === '' && $bankCode === '') {
            $validator->errors()->add($prefix . 'cheque_bank_name', 'Enter either cheque bank name or cheque bank code.');
        }

        if ($bankName !== '' && $bankCode !== '') {
            $validator->errors()->add($prefix . 'cheque_bank_name', 'Enter cheque bank name or cheque bank code, not both.');
        }
    }
}
