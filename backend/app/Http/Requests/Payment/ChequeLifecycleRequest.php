<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class ChequeLifecycleRequest extends FormRequest
{
    private const BANK_CODE_REGEX = '/^[A-Z0-9\-]{2,30}$/i';
    private const CHEQUE_NUMBER_REGEX = '/^[A-Z0-9\-]{1,50}$/i';
    private const ACCOUNT_NUMBER_REGEX = '/^[0-9]{6,20}$/';

    private const RETURN_CODES = [
        'refer_to_drawer',
        'insufficient_funds',
        'mismatched_signature',
        'payment_stopped',
        'account_closed',
        'stale_dated',
        'post_dated',
        'effects_not_cleared',
        'drawer_deceased',
        'alteration_requires_confirmation',
        'other',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cheque_bank_name' => ['nullable', 'string', 'max:120'],
            'cheque_bank_code' => ['nullable', 'string', 'max:30', 'regex:' . self::BANK_CODE_REGEX],
            'cheque_number' => ['nullable', 'string', 'max:50', 'regex:' . self::CHEQUE_NUMBER_REGEX],
            'cheque_date' => ['nullable', 'date'],
            'cheque_account_name' => ['nullable', 'string', 'max:120'],
            'cheque_account_number' => ['nullable', 'string', 'max:50', 'regex:' . self::ACCOUNT_NUMBER_REGEX],
            'cheque_branch_name' => ['nullable', 'string', 'max:120'],
            'cheque_notes' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'cheque_deposit_reference' => ['nullable', 'string', 'max:120'],
            'cheque_clearing_reference' => ['nullable', 'string', 'max:120'],
            'cheque_return_code' => ['nullable', 'string', 'in:' . implode(',', self::RETURN_CODES)],
            'cheque_return_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'cheque_bank_code.regex' => 'Bank code may contain only letters, numbers, and dashes.',
            'cheque_number.regex' => 'Cheque number may contain only letters, numbers, and dashes.',
            'cheque_account_number.regex' => 'Account number must contain 6 to 20 digits only.',
        ];
    }
}
