<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mpesa\InitiateB2bSupplierPaymentRequest;
use App\Http\Requests\Mpesa\InitiateStkPushRequest;
use App\Http\Requests\Mpesa\ValidateManualReceiptRequest;
use App\Models\Billing;
use App\Models\Grn;
use App\Models\MpesaTransaction;
use App\Models\PaymentVoucher;
use App\Services\Mpesa\MpesaB2bService;
use App\Services\Mpesa\MpesaService;
use App\Services\PaymentVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MpesaController extends Controller
{
    use AuthorizesPermission;

    public function __construct(
        private readonly MpesaService $service,
        private readonly MpesaB2bService $b2bService,
        private readonly PaymentVoucherService $voucherService,
    ) {
    }

    public function initiateStkPush(InitiateStkPushRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        $data = $request->validated();
        $user = $request->user();

        try {
            if (!empty($data['billing_id'])) {
                $billing = Billing::findOrFail($data['billing_id']);
                $txn = $this->service->initiateStkPushForBilling(
                    $billing,
                    $user,
                    $data['phone'],
                    $data['amount'] ?? null,
                    $data['split_allocations'] ?? [],
                    (int) ($data['points_redeemed'] ?? 0)
                );
            } else {
                $grn = Grn::findOrFail($data['grn_id']);
                $txn = $this->service->initiateStkPushForGrn(
                    $grn,
                    $user,
                    $data['phone'],
                    $data['amount'] ?? null
                );
            }

            return response()->json([
                'message' => 'STK Push sent. Ask the customer to enter their M-Pesa PIN.',
                'data' => [
                    'mpesa_transaction_id' => $txn->mpesa_transaction_id,
                    'checkout_request_id' => $txn->checkout_request_id,
                    'status' => $txn->status,
                    'amount' => (float) $txn->amount,
                    'phone_number' => $txn->phone_number,
                    'polling' => [
                        'interval_ms' => (int) config('mpesa.polling.interval_ms'),
                        'timeout_ms' => (int) config('mpesa.polling.timeout_ms'),
                    ],
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[Mpesa] STK push failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function initiateB2bSupplierPayment(InitiateB2bSupplierPaymentRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) {
            return $error;
        }

        $data = $request->validated();
        $user = $request->user();
        $paymentMethod = $this->normalizePaymentMethod($data['payment_method'] ?? '');

        if ($paymentMethod === 'cash') {
            try {
                $voucher = PaymentVoucher::query()->findOrFail((int) $data['voucher_id']);
                $cashRemarks = trim((string) ($data['remarks'] ?? ''));
                $supportingDocumentName = trim((string) ($data['supporting_document_name'] ?? ''));

                if ($supportingDocumentName !== '') {
                    $cashRemarks = trim($cashRemarks . ' | Supporting document: ' . $supportingDocumentName, ' |');
                }

                $result = $this->voucherService->processManualCashPayout($user, $voucher, [
                    'amount' => (float) $data['amount'],
                    'remarks' => $cashRemarks !== '' ? $cashRemarks : null,
                    'supporting_document_name' => $supportingDocumentName !== '' ? $supportingDocumentName : null,
                ]);

                return response()->json([
                    'message' => 'Manual cash payout posted successfully.',
                    'data' => [
                        'voucher' => $result['voucher'] ?? null,
                        'payment' => $result['payment'] ?? null,
                        'remaining_balance' => $result['voucher']?->remaining_balance ?? $result['voucher']?->balance_due,
                    ],
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        $voucher = null;
        $txn = null;

        try {
            [$voucher, $txn, $normalizedPhone, $previousStatus] = DB::transaction(function () use ($data, $user) {
                $voucher = PaymentVoucher::query()
                    ->with(['store', 'supplier', 'grn'])
                    ->lockForUpdate()
                    ->findOrFail((int) $data['voucher_id']);

                $this->voucherService->authorizeStoreAccess($user, $voucher->store_id);
                $voucher = $this->voucherService->recalculateVoucherFinancials($voucher);
                $currentStatus = $this->normalizeVoucherStatus($voucher->status);

                if ($currentStatus === 'override_required') {
                    throw new \RuntimeException('Payments are blocked while the voucher is in OVERRIDE_REQUIRED state.');
                }

                if (!in_array($currentStatus, ['authorized', 'partially_paid'], true)) {
                    throw new \RuntimeException('Payments can only execute if the voucher status is AUTHORIZED, APPROVED, or PARTIALLY_PAID.');
                }

                $amount = round((float) $data['amount'], 2);
                $remainingBalance = $this->voucherService->remainingBalanceForVoucher($voucher);
                if ($remainingBalance <= 0) {
                    throw new \RuntimeException('This payment voucher is already fully settled.');
                }

                if ($amount > $remainingBalance) {
                    throw new \RuntimeException('Requested amount exceeds outstanding voucher balance.');
                }

                $rawPhone = (string) ($data['phone'] ?? $data['phone_number'] ?? '');
                $normalizedPhone = $this->b2bService->normalizePhoneNumber($rawPhone);
                $accountReference = trim((string) (($data['account_reference'] ?? null) ?: ($voucher->voucher_number ?: ('PV-' . $voucher->payment_voucher_id . '-' . Str::upper(Str::random(6))))));
                $previousStatus = $currentStatus;

                $voucher->update([
                    'status' => 'processing',
                ]);

                $txn = MpesaTransaction::create([
                    'store_id' => $voucher->store_id,
                    'grn_id' => $voucher->grn_id,
                    'payment_voucher_id' => $voucher->payment_voucher_id,
                    'user_id' => $user->user_id,
                    'channel' => 'b2c',
                    'shortcode_type' => null,
                    'receiver_type' => 'mobile_wallet',
                    'amount' => $amount,
                    'phone_number' => $normalizedPhone,
                    'account_reference' => $accountReference,
                    'transaction_desc' => trim((string) ($data['remarks'] ?? ('Supplier settlement for voucher ' . ($voucher->voucher_number ?: $voucher->payment_voucher_id)))),
                    'status' => 'pending',
                    'environment' => $voucher->store?->mpesa_environment ?? config('mpesa.environment', 'sandbox'),
                    'request_payload' => [
                        'voucher_id' => $voucher->payment_voucher_id,
                        'payment_method' => 'mpesa',
                        'phone_number' => $normalizedPhone,
                        'previous_voucher_status' => $previousStatus,
                        'supporting_document_name' => $data['supporting_document_name'] ?? null,
                        'transaction_fee' => round((float) ($data['transaction_fee'] ?? 0), 2),
                    ],
                ]);

                return [$voucher, $txn, $normalizedPhone, $previousStatus];
            });

            $dispatch = $this->b2bService->initiate($voucher->store, [
                'amount' => (float) $txn->amount,
                'phone_number' => $normalizedPhone,
                'account_reference' => $txn->account_reference,
                'remarks' => $txn->transaction_desc,
                'occasion' => $voucher->voucher_number ?: $txn->account_reference,
                'transaction_fee' => (float) data_get($txn->request_payload, 'transaction_fee', 0),
            ]);

            $daraja = $dispatch['response'] ?? [];
            $txn->update([
                'status' => 'sent',
                'phone_number' => $dispatch['normalized_phone'] ?? $normalizedPhone,
                'originator_conversation_id' => data_get($daraja, 'OriginatorConversationID'),
                'conversation_id' => data_get($daraja, 'ConversationID'),
                'result_desc' => data_get($daraja, 'ResponseDescription'),
                'request_payload' => [
                    ...($txn->request_payload ?? []),
                    'b2c_request' => $dispatch['request_payload'] ?? [],
                    'b2c_response' => $daraja,
                    'previous_voucher_status' => $previousStatus,
                ],
            ]);

            return response()->json([
                'message' => 'Supplier M-Pesa payout request accepted and is awaiting Safaricom callback.',
                'data' => [
                    'mpesa_transaction_id' => $txn->mpesa_transaction_id,
                    'voucher_id' => $voucher->payment_voucher_id,
                    'status' => $txn->status,
                    'tracking_reference' => $txn->originator_conversation_id ?: $txn->account_reference,
                    'originator_conversation_id' => $txn->originator_conversation_id,
                    'conversation_id' => $txn->conversation_id,
                    'account_reference' => $txn->account_reference,
                    'amount' => (float) $txn->amount,
                    'phone_number' => $txn->phone_number,
                    'message' => data_get($daraja, 'ResponseDescription', 'Pending supplier payout callback.'),
                ],
            ], 202);
        } catch (\Throwable $e) {
            if ($txn) {
                DB::transaction(function () use ($txn, $voucher, $e) {
                    $txn->update([
                        'status' => 'failed',
                        'result_code' => 'SUPPLIER_PAYOUT_INIT_ERROR',
                        'result_desc' => $e->getMessage(),
                    ]);

                    if ($voucher) {
                        $freshVoucher = PaymentVoucher::query()
                            ->lockForUpdate()
                            ->find($voucher->payment_voucher_id);

                        if ($freshVoucher && strtolower((string) $freshVoucher->status) === 'processing') {
                            $previous = strtolower((string) data_get($txn->request_payload, 'previous_voucher_status', 'authorized'));
                            $freshVoucher->update([
                                'status' => in_array($previous, ['authorized', 'partially_paid'], true) ? $previous : 'authorized',
                            ]);
                        }
                    }
                });
            }

            Log::error('[Mpesa][SupplierPayout] Supplier payment initiation failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function status(string $checkoutRequestId): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        return response()->json([
            'data' => $this->service->status($checkoutRequestId),
        ]);
    }

    public function b2bStatus(string $trackingReference): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        try {
            return response()->json([
                'data' => $this->b2bService->localStatus($trackingReference),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    public function validateReceipt(ValidateManualReceiptRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        $data = $request->validated();

        try {
            $billing = Billing::findOrFail($data['billing_id']);
            $result = $this->service->validateManualReceipt(
                $billing,
                $request->user(),
                $data['mpesa_receipt'],
                (float) $data['amount'],
                $data['phone'] ?? null,
                $data['split_allocations'] ?? [],
                (int) ($data['points_redeemed'] ?? 0)
            );

            return response()->json([
                'message' => ($result['status'] ?? null) === 'success'
                    ? 'M-Pesa receipt validated and payment recorded.'
                    : 'M-Pesa receipt validation sent. Waiting for Safaricom confirmation.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function manualStatus(string $trackingReference): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        return response()->json([
            'data' => $this->service->manualStatus($trackingReference),
        ]);
    }

    public function pullMatch(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        $data = $request->validate([
            'billing_id' => ['required', 'integer', 'exists:billing,billing_id'],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'lookback_minutes' => ['nullable', 'integer', 'min:1', 'max:2880'],
            'points_redeemed' => ['nullable', 'integer', 'min:0'],
            'split_allocations' => ['nullable', 'array', 'min:1'],
            'split_allocations.*.payment_method' => ['required_with:split_allocations', 'string'],
            'split_allocations.*.amount_received' => ['required_with:split_allocations', 'numeric', 'gt:0'],
            'split_allocations.*.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'split_allocations.*.mpesa_phone' => ['nullable', 'string', 'max:30'],
            'split_allocations.*.mpesa_code' => ['nullable', 'string', 'max:50'],
            'split_allocations.*.mpesa_mode' => ['nullable', 'string', 'max:20'],
            'split_allocations.*.card_reference' => ['nullable', 'string', 'max:100'],
            'split_allocations.*.card_holder' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $billing = Billing::findOrFail($data['billing_id']);
            $result = $this->service->pullMatchForBilling(
                $billing,
                $request->user(),
                $data['phone'],
                (float) $data['amount'],
                $data['split_allocations'] ?? [],
                (int) ($data['points_redeemed'] ?? 0),
                isset($data['lookback_minutes']) ? (int) $data['lookback_minutes'] : null,
            );

            $status = $result['status'] ?? 'unknown';
            $httpCode = $status === 'success' ? 200 : 404;
            $message = $status === 'success'
                ? 'Matching M-Pesa payment found and recorded.'
                : 'No matching recent M-Pesa payment was found for that phone and amount.';

            return response()->json([
                'message' => $message,
                'data' => $result,
            ], $httpCode);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(string $checkoutRequestId, Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        $txn = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)->first();
        if (!$txn) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        if (!in_array($txn->status, ['pending', 'sent'], true)) {
            return response()->json(['message' => 'Transaction is already ' . $txn->status . '.'], 422);
        }

        $txn->update([
            'status' => 'cancelled',
            'result_desc' => 'Manually cancelled by cashier',
        ]);

        return response()->json(['message' => 'Transaction cancelled.']);
    }

    private function normalizeVoucherStatus(?string $status): string
    {
        $safe = strtolower((string) $status);
        return match ($safe) {
            'approved' => 'authorized',
            'partial' => 'partially_paid',
            'pending_settlement' => 'processing',
            default => $safe,
        };
    }

    private function normalizePaymentMethod(?string $value): string
    {
        $safe = strtolower(trim((string) $value));

        return match ($safe) {
            'm-pesa' => 'mpesa',
            default => $safe,
        };
    }
}
