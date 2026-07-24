<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChargeBillingRequest extends FormRequest
{
    private const BANK_CODE_REGEX = '/^[A-Z0-9\-]{2,30}$/i';
    private const CHEQUE_NUMBER_REGEX = '/^[A-Z0-9\-]{1,50}$/i';
    private const ACCOUNT_NUMBER_REGEX = '/^[0-9]{6,20}$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $pointsRedeemed = (int) $this->input('points_redeemed', 0);
        $amountReceived = (float) $this->input('amount_received', 0);

        $amountReceivedRule = ($pointsRedeemed > 0 && $amountReceived == 0)
            ? ['required_without:payment_allocations', 'numeric', 'min:0']
            : ['required_without:payment_allocations', 'numeric', 'gt:0'];

        return [
            'payment_method'  => ['required_without:payment_allocations', Rule::in(['cash', 'mpesa', 'card', 'cheque'])],
            'amount_received' => $amountReceivedRule,
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'points_redeemed' => ['nullable', 'integer', 'min:0'],
            'mpesa_phone'     => ['nullable', 'string', 'max:20'],
            'mpesa_code'      => ['nullable', 'string', 'max:50'],
            'mpesa_mode'      => ['nullable', Rule::in(['stk', 'manual', 'till'])],
            'card_reference'  => ['nullable', 'string', 'max:100'],
            'card_holder'     => ['nullable', 'string', 'max:100'],
            'cheque_bank_name' => ['nullable', 'string', 'max:120'],
            'cheque_bank_code' => ['nullable', 'string', 'max:30', 'regex:' . self::BANK_CODE_REGEX],
            'cheque_number' => ['nullable', 'string', 'max:50', 'regex:' . self::CHEQUE_NUMBER_REGEX],
            'cheque_date' => ['nullable', 'date'],
            'cheque_account_name' => ['nullable', 'string', 'max:120'],
            'cheque_account_number' => ['nullable', 'string', 'max:50', 'regex:' . self::ACCOUNT_NUMBER_REGEX],
            'cheque_branch_name' => ['nullable', 'string', 'max:120'],
            'cheque_notes' => ['nullable', 'string', 'max:2000'],

            'payment_allocations' => ['nullable', 'array', 'min:1'],
            'payment_allocations.*.payment_method' => ['required_with:payment_allocations', Rule::in(['cash', 'mpesa', 'card', 'cheque'])],
            'payment_allocations.*.amount_received' => ['required_with:payment_allocations', 'numeric', 'gt:0'],
            'payment_allocations.*.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'payment_allocations.*.mpesa_phone' => ['nullable', 'string', 'max:20'],
            'payment_allocations.*.mpesa_code' => ['nullable', 'string', 'max:50'],
            'payment_allocations.*.mpesa_mode' => ['nullable', Rule::in(['stk', 'manual', 'till'])],
            'payment_allocations.*.card_reference' => ['nullable', 'string', 'max:100'],
            'payment_allocations.*.card_holder' => ['nullable', 'string', 'max:100'],
            'payment_allocations.*.cheque_bank_name' => ['nullable', 'string', 'max:120'],
            'payment_allocations.*.cheque_bank_code' => ['nullable', 'string', 'max:30', 'regex:' . self::BANK_CODE_REGEX],
            'payment_allocations.*.cheque_number' => ['nullable', 'string', 'max:50', 'regex:' . self::CHEQUE_NUMBER_REGEX],
            'payment_allocations.*.cheque_date' => ['nullable', 'date'],
            'payment_allocations.*.cheque_account_name' => ['nullable', 'string', 'max:120'],
            'payment_allocations.*.cheque_account_number' => ['nullable', 'string', 'max:50', 'regex:' . self::ACCOUNT_NUMBER_REGEX],
            'payment_allocations.*.cheque_branch_name' => ['nullable', 'string', 'max:120'],
            'payment_allocations.*.cheque_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateChequeBankChoice(
                $validator,
                [
                    'payment_method' => $this->input('payment_method'),
                    'cheque_bank_name' => $this->input('cheque_bank_name'),
                    'cheque_bank_code' => $this->input('cheque_bank_code'),
                ],
                ''
            );

            foreach ((array) $this->input('payment_allocations', []) as $index => $row) {
                $this->validateChequeBankChoice($validator, (array) $row, "payment_allocations.{$index}.");
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

    public function messages(): array
    {
        return [
            'amount_received.gt'  => 'Amount received must be greater than zero.',
            'amount_received.min' => 'Amount received cannot be negative.',
            'amount_tendered.min' => 'Amount tendered cannot be negative.',
            'cheque_bank_code.regex' => 'Bank code must contain only letters, numbers, and dashes.',
            'cheque_number.regex' => 'Cheque number may contain only letters, numbers, and dashes.',
            'cheque_account_number.regex' => 'Account number must contain 6 to 20 digits only.',
            'payment_allocations.*.cheque_bank_code.regex' => 'Bank code must contain only letters, numbers, and dashes.',
            'payment_allocations.*.cheque_number.regex' => 'Cheque number may contain only letters, numbers, and dashes.',
            'payment_allocations.*.cheque_account_number.regex' => 'Account number must contain 6 to 20 digits only.',
        ];
    }
}
