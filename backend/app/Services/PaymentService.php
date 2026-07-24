<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Support\ChequeBankDirectory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\Mpesa\MpesaService;

class PaymentService
{
    private const CHEQUE_RETURN_CODES = [
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

    public function __construct(
        private readonly BillingService $billingService,
        private readonly DocumentNumberService $documentNumberService,
        private readonly AuditLogService $auditLogService,
        private readonly LoyaltyService $loyaltyService,
        private readonly CashierShiftService $cashierShiftService,
    ) {
    }

    public function charge(Billing $billing, User $user, array $data): Payment
    {
        return DB::transaction(function () use ($billing, $user, $data) {
            $billing = Billing::query()
                ->whereKey($billing->billing_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (method_exists($billing, 'trashed') && $billing->trashed()) {
                $this->abort422('Cannot record payment for a trashed billing.');
            }

            if ($billing->status === 'paid') {
                $this->abort422('This billing has already been fully paid.');
            }

            $this->cashierShiftService->requireOpenShift($user, (int) $billing->store_id);

            $billing = $this->billingService->finalizeIfNeeded($billing, $user);
            $billing = Billing::query()
                ->whereKey($billing->billing_id)
                ->lockForUpdate()
                ->firstOrFail();

            $billing->loadMissing('items.product');
            $pointsToRedeem = $this->sanitizePointsToRedeem($billing, (int) ($data['points_redeemed'] ?? 0));

            if ($pointsToRedeem > 0 && $billing->customer_id) {
                $this->applyPointsRedemption($billing, $pointsToRedeem);
                $billing = Billing::query()
                    ->whereKey($billing->billing_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $billing->loadMissing('items.product');
            }

            $allocations = $this->normalisePaymentAllocations($data);
            $zeroPaymentMethod = $data['payment_method'] ?? ($allocations[0]['payment_method'] ?? 'cash');

            if ((float) $billing->balance_due <= 0) {
                return $this->completeZeroBalanceBilling($billing, $zeroPaymentMethod);
            }

            foreach ($allocations as $allocation) {
                if ($allocation['payment_method'] === 'mpesa' && ($allocation['mpesa_mode'] ?? null) === 'stk') {
                    $this->abort422('Live M-Pesa STK allocations must be completed from the M-Pesa callback flow.');
                }
            }

            [$openBillings, $previousDebtsSum] = $this->loadOpenBillingsForAllocation($billing, $billing->customer_id);
            $totalCombinedBalance = round((float) $openBillings->sum('balance_due'), 2);
            if ($totalCombinedBalance <= 0) {
                $this->abort422('This customer has no outstanding balances due.');
            }

            $allocationsTotal = round(array_reduce($allocations, function ($sum, $allocation) {
                return $sum + (float) $allocation['amount_received'];
            }, 0), 2);

            if (abs($allocationsTotal - $totalCombinedBalance) > 0.01) {
                $this->abort422('Remaining balance must be exactly zero before processing the sale.');
            }

            $primaryPaymentRecord = $this->applyAllocations($billing, $openBillings, $allocations);

            if (!$primaryPaymentRecord) {
                $this->abort422('No payment could be created for this sale.');
            }

            if (!empty($billing->customer_id)) {
                $freshCustomerBalance = round((float) Billing::where('customer_id', $billing->customer_id)
                    ->where('status', '!=', 'paid')
                    ->where('is_draft', false)
                    ->sum('balance_due'), 2);

                Customer::where('customer_id', $billing->customer_id)->update([
                    'current_balance' => $freshCustomerBalance,
                ]);
            }

            $freshPayment = $primaryPaymentRecord->fresh();
            $currentInvoice = Billing::query()->findOrFail($billing->billing_id);
            $freshPayment->balance_calculation_label = '+' . round((float) $currentInvoice->balance_due, 2) . '/+' . $previousDebtsSum;

            $this->applyPostPaymentLoyalty($currentInvoice);

            return $freshPayment->load([
                'billing.customer',
                'billing.store',
                'billing.user',
            ]);
        });
    }

    public function chargeCart(User $user, array $data): array
    {
        $this->billingService->authorizeStoreAccess($user, $data['store_id']);
        $this->cashierShiftService->requireOpenShift($user, (int) $data['store_id']);

        return DB::transaction(function () use ($user, $data) {
            $billing = Billing::create([
                'store_id' => $data['store_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'user_id' => $user->user_id,
                'invnumber' => null,
                'status' => 'unpaid',
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
                'paid_amount' => 0,
                'balance_due' => 0,
                'is_draft' => true,
                'billing_date' => now(),
                'notes' => $data['notes'] ?? null,
                'fulfillment_status' => 'pending',
                'fulfillment_type' => 'walk_in_counter',
            ]);

            foreach ($data['items'] as $item) {
                $qty = (int) $item['quantity'];
                $unitPrice = round((float) $item['price'], 2);
                $vatRate = (float) ($item['vat_rate'] ?? 16);

                $totalAmount = $qty * $unitPrice;
                $lineSubtotal = $totalAmount / (1 + ($vatRate / 100));
                $vatAmount = $totalAmount - $lineSubtotal;

                $billing->items()->create([
                    'product_id' => (int) $item['product_id'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'vat_rate' => $vatRate,
                    'line_subtotal' => round($lineSubtotal, 2),
                    'vat_amount' => round($vatAmount, 2),
                    'total_amount' => round($totalAmount, 2),
                ]);
            }

            $billing = $this->billingService->recalculateTotals($billing->fresh());

            $chargePayload = [
                'points_redeemed' => (int) ($data['points_redeemed'] ?? 0),
            ];

            if (!empty($data['payment_allocations']) && is_array($data['payment_allocations'])) {
                $chargePayload['payment_allocations'] = $data['payment_allocations'];
            } else {
                $chargePayload = [
                    ...$chargePayload,
                    'payment_method' => $data['payment_method'],
                    'amount_received' => (float) ($data['amount_received'] ?? $data['amount_tendered'] ?? 0),
                    'amount_tendered' => (float) ($data['amount_tendered'] ?? 0),
                    'mpesa_phone' => $data['mpesa_phone'] ?? null,
                    'mpesa_code' => $data['mpesa_code'] ?? null,
                    'card_reference' => $data['card_reference'] ?? null,
                    'card_holder' => $data['card_holder'] ?? null,
                    'cheque_bank_name' => $data['cheque_bank_name'] ?? null,
                    'cheque_bank_code' => $data['cheque_bank_code'] ?? null,
                    'cheque_number' => $data['cheque_number'] ?? null,
                    'cheque_date' => $data['cheque_date'] ?? null,
                    'cheque_account_name' => $data['cheque_account_name'] ?? null,
                    'cheque_account_number' => $data['cheque_account_number'] ?? null,
                    'cheque_branch_name' => $data['cheque_branch_name'] ?? null,
                    'cheque_notes' => $data['cheque_notes'] ?? null,
                ];
            }

            $payment = $this->charge($billing, $user, $chargePayload);

            $freshBilling = Billing::with([
                'customer',
                'store',
                'user',
                'items.product.category',
                'payments',
            ])->findOrFail($billing->billing_id);

            return [
                'billing' => $freshBilling,
                'payment' => $payment->fresh()->load([
                    'billing.customer',
                    'billing.store',
                    'billing.user',
                ]),
            ];
        });
    }

    public function requestMpesaReversal(Payment $payment, User $user, array $data): Payment
{
    return DB::transaction(function () use ($payment, $user, $data) {
        $payment = Payment::query()->with(['billing.customer', 'billing.store', 'billing.user'])->lockForUpdate()->findOrFail($payment->payment_id);

        if (strtolower((string) $payment->payment_method) !== 'mpesa') {
            $this->abort422('Only M-Pesa payments can be reversed here.');
        }
        if (strtolower((string) $payment->status) !== 'paid') {
            $this->abort422('Only paid M-Pesa payments can be reversed.');
        }
        if (blank($payment->mpesa_receipt)) {
            $this->abort422('This payment has no M-Pesa receipt code to reverse.');
        }

        $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        $reversal = is_array(data_get($meta, 'reversal')) ? data_get($meta, 'reversal') : [];
        $status = strtolower((string) ($reversal['status'] ?? ''));

        if (in_array($status, ['pending_approval', 'processing', 'completed'], true)) {
            $this->abort422('A reversal request already exists for this payment.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            $this->abort422('Reversal reason is required.');
        }

        $meta['reversal'] = [
            ...$reversal,
            'status' => 'pending_approval',
            'reason' => $reason,
            'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
            'requested_at' => now()->toAtomString(),
            'requested_by' => (int) $user->user_id,
            'requested_by_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? 'Unknown user'),
        ];

        $old = ['payment_meta' => $payment->payment_meta];
        $payment->update(['payment_meta' => $meta]);
        $this->audit('payment.mpesa_reversal.requested', $payment, $old);

        return $payment->fresh()->load(['billing.customer', 'billing.store', 'billing.user']);
    });
}

public function approveMpesaReversal(Payment $payment, User $user, array $data): Payment
{
    return DB::transaction(function () use ($payment, $user, $data) {
        $payment = Payment::query()->with(['billing.store', 'billing.customer', 'billing.user'])->lockForUpdate()->findOrFail($payment->payment_id);

        $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        $reversal = is_array(data_get($meta, 'reversal')) ? data_get($meta, 'reversal') : [];
        if (($reversal['status'] ?? null) !== 'pending_approval') {
            $this->abort422('This payment does not have a pending reversal request.');
        }

        /** @var MpesaService $mpesa */
        $mpesa = app(MpesaService::class);
        $response = $mpesa->initiateReversalForPayment($payment, $user, [
            'remarks' => trim((string) ($data['remarks'] ?? '')),
            'occasion' => 'REV-PAY-' . $payment->payment_id,
        ]);

        $meta['reversal'] = [
            ...$reversal,
            'status' => 'processing',
            'approved_at' => now()->toAtomString(),
            'approved_by' => (int) $user->user_id,
            'approved_by_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? 'Unknown user'),
            'approval_remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
            'originator_conversation_id' => $response['OriginatorConversationID'] ?? null,
            'conversation_id' => $response['ConversationID'] ?? null,
            'response_payload' => $response,
        ];

        $old = ['payment_meta' => $payment->payment_meta];
        $payment->update(['payment_meta' => $meta]);
        $this->audit('payment.mpesa_reversal.approved', $payment, $old);

        return $payment->fresh()->load(['billing.customer', 'billing.store', 'billing.user']);
    });
}

public function rejectMpesaReversal(Payment $payment, User $user, array $data): Payment
{
    return DB::transaction(function () use ($payment, $user, $data) {
        $payment = Payment::query()->with(['billing.customer', 'billing.store', 'billing.user'])->lockForUpdate()->findOrFail($payment->payment_id);

        $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        $reversal = is_array(data_get($meta, 'reversal')) ? data_get($meta, 'reversal') : [];
        if (($reversal['status'] ?? null) !== 'pending_approval') {
            $this->abort422('This payment does not have a pending reversal request.');
        }

        $meta['reversal'] = [
            ...$reversal,
            'status' => 'rejected',
            'rejected_at' => now()->toAtomString(),
            'rejected_by' => (int) $user->user_id,
            'rejected_by_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? 'Unknown user'),
            'rejection_reason' => trim((string) ($data['reason'] ?? '')),
            'rejection_remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
        ];

        $old = ['payment_meta' => $payment->payment_meta];
        $payment->update(['payment_meta' => $meta]);
        $this->audit('payment.mpesa_reversal.rejected', $payment, $old);

        return $payment->fresh()->load(['billing.customer', 'billing.store', 'billing.user']);
    });
}

public function finalizeMpesaReversal(Payment $payment, array $payload, bool $success): Payment
{
    return DB::transaction(function () use ($payment, $payload, $success) {
        $payment = Payment::query()->with(['billing.customer', 'billing.store', 'billing.user'])->lockForUpdate()->findOrFail($payment->payment_id);

        $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        $reversal = is_array(data_get($meta, 'reversal')) ? data_get($meta, 'reversal') : [];

        $meta['reversal'] = [
            ...$reversal,
            'status' => $success ? 'completed' : 'failed',
            'callback_received_at' => now()->toAtomString(),
            'callback_payload' => $payload,
            'result_code' => data_get($payload, 'Result.ResultCode'),
            'result_desc' => data_get($payload, 'Result.ResultDesc'),
        ];

        $old = $payment->only(['status', 'payment_meta']);
        $payment->update([
            'status' => $success ? 'refunded' : $payment->status,
            'payment_meta' => $meta,
        ]);

        if ($success) {
            $billing = $this->syncBillingByPayment($payment);
            if ($billing) {
                $this->refreshCustomerBalanceForBilling($billing);
            }
        }

        $this->audit($success ? 'payment.mpesa_reversal.completed' : 'payment.mpesa_reversal.failed', $payment, $old);
        return $payment->fresh()->load(['billing.customer', 'billing.store', 'billing.user']);
    });
}

    // ──────────────────────────────────────────────────────────────────────
    // Cheque lifecycle (fix for POST /api/payments/{id}/cheque/* 404s)
    // ──────────────────────────────────────────────────────────────────────

    public function chequeBanks(): array
    {
        return ChequeBankDirectory::all();
    }

    public function authorizeCheque(Payment $payment, User $user, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $user, $data) {
            $payment = Payment::query()
                ->whereKey($payment->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureIsCheque($payment);
            $this->ensureChequeStage($payment, ['pending', 'authorized'], 'authorize');

            $update = array_merge(
                ['cheque_status' => 'authorized'],
                $this->normaliseChequeMeta($data),
                $this->stampField('cheque_authorized_at', now()),
                $this->stampField('cheque_authorized_by', (int) $user->user_id),
                $this->stampField('cheque_authorized_ip', $this->resolveActorIp($data))
            );

            $old = $payment->only(array_keys($update));
            $payment->update($update);

            $this->audit('payment.cheque.authorize', $payment, $old);

            return $payment->fresh()->load(['billing.customer', 'billing.store', 'billing.user']);
        });
    }

    public function verifyCheque(Payment $payment, User $user, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $user, $data) {
            $payment = Payment::query()
                ->whereKey($payment->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureIsCheque($payment);
            $this->ensureChequeStage($payment, ['authorized'], 'verify');

            $update = array_merge(
                ['cheque_status' => 'verified'],
                $this->normaliseChequeMeta($data),
                $this->stampField('cheque_verified_at', now()),
                $this->stampField('cheque_verified_by', (int) $user->user_id),
                $this->stampField('cheque_verified_ip', $this->resolveActorIp($data))
            );

            $old = $payment->only(array_keys($update));
            $payment->update($update);

            $this->audit('payment.cheque.verify', $payment, $old);

            return $payment->fresh()->load(['billing.customer', 'billing.store', 'billing.user']);
        });
    }

    public function submitCheque(Payment $payment, User $user, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $user, $data) {
            $payment = Payment::query()
                ->whereKey($payment->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureIsCheque($payment);
            $this->ensureChequeStage($payment, ['verified'], 'submit');
            $this->validateMicrCodeline($this->mergedChequeSnapshot($payment, $data));

            $update = array_merge(
                ['cheque_status' => 'submitted'],
                $this->normaliseChequeMeta($data),
                $this->stampField('cheque_submitted_at', now()),
                $this->stampField('cheque_submitted_by', (int) $user->user_id),
                $this->stampField('cheque_submitted_ip', $this->resolveActorIp($data))
            );

            $old = $payment->only(array_keys($update));
            $payment->update($update);

            $this->audit('payment.cheque.submit', $payment, $old);

            return $payment->fresh()->load(['billing.customer', 'billing.store', 'billing.user']);
        });
    }

    public function depositCheque(Payment $payment, User $user, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $user, $data) {
            $payment = Payment::query()
                ->whereKey($payment->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureIsCheque($payment);
            $this->ensureChequeStage($payment, ['submitted'], 'deposit');

            $depositReference = trim((string) ($data['cheque_deposit_reference'] ?? ''));
            if ($depositReference === '') {
                $this->abort422('A deposit reference is required to record the cheque deposit.');
            }

            $update = array_merge(
                ['cheque_status' => 'deposited'],
                $this->normaliseChequeMeta($data),
                ['cheque_deposit_reference' => $depositReference],
                $this->stampField('cheque_deposited_at', now()),
                $this->stampField('cheque_deposited_by', (int) $user->user_id),
                $this->stampField('cheque_deposited_ip', $this->resolveActorIp($data))
            );

            $old = $payment->only(array_keys($update));
            $payment->update($update);

            $this->audit('payment.cheque.deposit', $payment, $old);

            return $payment->fresh()->load(['billing.customer', 'billing.store', 'billing.user']);
        });
    }

    public function clearCheque(Payment $payment, User $user, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $user, $data) {
            $payment = Payment::query()
                ->whereKey($payment->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureIsCheque($payment);
            $this->ensureChequeStage($payment, ['deposited'], 'clear');

            $clearingReference = trim((string) ($data['cheque_clearing_reference'] ?? ''));
            if ($clearingReference === '') {
                $this->abort422('A clearing reference is required to mark the cheque as cleared.');
            }

            $update = array_merge(
                [
                    'cheque_status' => 'cleared',
                    'status' => 'paid',
                ],
                $this->normaliseChequeMeta($data),
                ['cheque_clearing_reference' => $clearingReference],
                $this->stampField('cheque_cleared_at', now()),
                $this->stampField('cheque_cleared_by', (int) $user->user_id),
                $this->stampField('cheque_cleared_ip', $this->resolveActorIp($data))
            );

            $old = $payment->only(array_keys($update));
            $payment->update($update);

            $billing = $this->syncBillingByPayment($payment);
            if ($billing) {
                $this->refreshCustomerBalanceForBilling($billing);
            }

            $this->audit('payment.cheque.clear', $payment, $old);

            return $payment->fresh()->load(['billing.customer', 'billing.store', 'billing.user']);
        });
    }


    public function returnCheque(Payment $payment, User $user, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $user, $data) {
            $payment = Payment::query()
                ->whereKey($payment->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureIsCheque($payment);
            $this->ensureChequeStage($payment, ['submitted', 'deposited', 'cleared'], 'return');

            $returnCode = strtolower(trim((string) ($data['cheque_return_code'] ?? '')));
            if ($returnCode === '') {
                $this->abort422('A cheque return code is required.');
            }
            if (!in_array($returnCode, self::CHEQUE_RETURN_CODES, true)) {
                $this->abort422('Unsupported cheque return code supplied.');
            }

            $returnReason = trim((string) ($data['cheque_return_reason'] ?? ''));

            $update = array_merge(
                [
                    'cheque_status' => 'returned',
                    'status' => 'failed',
                    'cheque_return_code' => $returnCode,
                    'cheque_return_reason' => $returnReason !== '' ? $returnReason : null,
                ],
                $this->normaliseChequeMeta($data),
                $this->stampField('cheque_returned_at', now()),
                $this->stampField('cheque_returned_by', (int) $user->user_id),
                $this->stampField('cheque_returned_ip', $this->resolveActorIp($data))
            );

            $old = $payment->only(array_keys($update));
            $payment->update($update);

            $billing = $this->syncBillingByPayment($payment);
            if ($billing) {
                $this->refreshCustomerBalanceForBilling($billing);
            }

            $this->audit('payment.cheque.return', $payment, $old);

            return $payment->fresh()->load(['billing.customer', 'billing.store', 'billing.user']);
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Cheque helpers
    // ──────────────────────────────────────────────────────────────────────

    private function ensureIsCheque(Payment $payment): void
    {
        if (strtolower((string) $payment->payment_method) !== 'cheque') {
            $this->abort422('This payment is not a cheque and cannot be processed through the cheque lifecycle.');
        }
    }

    private function ensureChequeStage(Payment $payment, array $allowedPrevStages, string $nextAction): void
    {
        $current = strtolower(trim((string) ($payment->cheque_status ?? 'pending')));
        $allowed = array_map('strtolower', $allowedPrevStages);

        if (!in_array($current, $allowed, true)) {
            $expected = implode(' or ', array_map(fn ($s) => "'{$s}'", $allowedPrevStages));
            $this->abort422(
                "Cheque cannot be moved to '{$nextAction}' from its current status '{$current}'. Required prior status: {$expected}."
            );
        }
    }

    private function normaliseChequeMeta(array $data): array
    {
        $map = [
            'cheque_bank_name' => 'cheque_bank_name',
            'cheque_bank_code' => 'cheque_bank_code',
            'cheque_number' => 'cheque_number',
            'cheque_date' => 'cheque_date',
            'cheque_account_name' => 'cheque_account_name',
            'cheque_account_number' => 'cheque_account_number',
            'cheque_branch_name' => 'cheque_branch_name',
            'cheque_notes' => 'cheque_notes',
            'notes' => 'cheque_notes',
            'cheque_return_code' => 'cheque_return_code',
            'cheque_return_reason' => 'cheque_return_reason',
        ];

        $out = [];
        foreach ($map as $inputKey => $column) {
            if (!array_key_exists($inputKey, $data)) {
                continue;
            }
            $value = $data[$inputKey];
            if ($value === null || $value === '') {
                continue;
            }
            if (in_array($column, ['cheque_bank_code', 'cheque_number'], true)) {
                $value = strtoupper(trim((string) $value));
            } elseif (in_array($column, ['cheque_bank_name', 'cheque_account_name', 'cheque_branch_name'], true)) {
                $value = trim((string) $value);
            }
            $out[$column] = $value;
        }
        return $out;
    }

    private function stampField(string $column, mixed $value): array
    {
        return [$column => $value];
    }

    private function resolveActorIp(array $data): ?string
    {
        $ip = trim((string) ($data['transition_ip'] ?? ''));

        return $ip !== '' ? $ip : null;
    }

    private function mergedChequeSnapshot(Payment $payment, array $data): array
    {
        return [
            'cheque_bank_name' => $data['cheque_bank_name'] ?? $payment->cheque_bank_name,
            'cheque_bank_code' => $data['cheque_bank_code'] ?? $payment->cheque_bank_code,
            'cheque_number' => $data['cheque_number'] ?? $payment->cheque_number,
            'cheque_account_number' => $data['cheque_account_number'] ?? $payment->cheque_account_number,
        ];
    }

    private function validateMicrCodeline(array $payload): void
    {
        $bankName = trim((string) ($payload['cheque_bank_name'] ?? ''));
        $bankCode = strtoupper(trim((string) ($payload['cheque_bank_code'] ?? '')));
        $chequeNumber = strtoupper(trim((string) ($payload['cheque_number'] ?? '')));
        $accountNumber = trim((string) ($payload['cheque_account_number'] ?? ''));

        if ($bankName === '' && $bankCode === '') {
            $this->abort422('Enter either the issuing bank name or the bank code before cheque submission.');
        }

        if ($bankName !== '' && $bankCode !== '') {
            $this->abort422('Enter the issuing bank name or the bank code before cheque submission, not both.');
        }

        if ($bankCode !== '' && !preg_match('/^[A-Z0-9\-]{2,30}$/', $bankCode)) {
            $this->abort422('Bank code may contain only letters, numbers, and dashes before cheque submission.');
        }

        if (!preg_match('/^[A-Z0-9\-]{1,50}$/', $chequeNumber)) {
            $this->abort422('Cheque number may contain only letters, numbers, and dashes before cheque submission.');
        }

        if ($accountNumber !== '' && !preg_match('/^[0-9]{6,20}$/', $accountNumber)) {
            $this->abort422('Account number must contain 6 to 20 digits only when supplied.');
        }
    }

    private function audit(string $event, Payment $payment, array $old): void
    {
        $this->auditLogService->log(
            $event,
            $payment,
            $old,
            $payment->fresh()->only(array_keys($old)),
            [
                'payment_id' => $payment->payment_id,
                'billing_id' => $payment->billing_id,
            ],
            $payment->billing?->store_id
        );
    }

    private function syncBillingByPayment(Payment $payment): ?Billing
    {
        $billing = Billing::query()
            ->whereKey($payment->billing_id)
            ->lockForUpdate()
            ->first();

        return $billing ? $this->syncBillingSettlementState($billing) : null;
    }

    private function syncBillingSettlementState(Billing $billing): Billing
    {
        $billing = Billing::query()
            ->whereKey($billing->billing_id)
            ->lockForUpdate()
            ->firstOrFail();

        $settledAmount = round((float) $billing->payments()
            ->where('status', 'paid')
            ->sum('amount_received'), 2);

        $hasPendingCheque = $billing->payments()
            ->where('payment_method', 'cheque')
            ->whereNotIn('status', ['paid', 'failed', 'refunded'])
            ->exists();

        $balance = round(max((float) $billing->total - $settledAmount, 0), 2);

        if ($balance <= 0) {
            $status = $hasPendingCheque ? 'pending' : 'paid';
        } elseif ($hasPendingCheque) {
            $status = 'pending';
        } elseif ($settledAmount > 0) {
            $status = 'partial';
        } else {
            $status = 'unpaid';
        }

        $billing->update([
            'paid_amount' => $settledAmount,
            'balance_due' => $balance,
            'status' => $status,
            'is_draft' => false,
        ]);

        $billing->paid_amount = $settledAmount;
        $billing->balance_due = $balance;
        $billing->status = $status;
        $billing->is_draft = false;

        return $billing;
    }

    private function refreshCustomerBalanceForBilling(Billing $billing): void
    {
        if (empty($billing->customer_id)) {
            return;
        }

        $freshBalance = round((float) Billing::where('customer_id', $billing->customer_id)
            ->where('status', '!=', 'paid')
            ->where('is_draft', false)
            ->sum('balance_due'), 2);

        Customer::where('customer_id', $billing->customer_id)
            ->update(['current_balance' => $freshBalance]);
    }

    // ── existing helpers (unchanged) ────────────────────────────────────

    private function sanitizePointsToRedeem(Billing $billing, int $pointsToRedeem): int
    {
        if ($pointsToRedeem < 0) {
            $this->abort422('Points to redeem cannot be negative.');
        }

        if ($pointsToRedeem <= 0 || !$billing->customer_id) {
            return 0;
        }

        $customerPoints = $this->loyaltyService->getCustomerPoints(
            (int) $billing->store_id,
            (int) $billing->customer_id
        );

        $pointsToRedeem = min($pointsToRedeem, $customerPoints);
        if ($pointsToRedeem <= 0) {
            return 0;
        }

        $activeRule = $this->loyaltyService->getActiveRule((int) $billing->store_id);
        $pointValue = (float) ($activeRule?->point_value ?? 1);
        $maxByInvoice = $pointValue > 0
            ? (int) floor((float) $billing->total / $pointValue)
            : 0;

        return max(min($pointsToRedeem, $maxByInvoice), 0);
    }

    private function applyPointsRedemption(Billing $billing, int $pointsToRedeem): void
    {
        try {
            $redemption = $this->loyaltyService->redeemPoints(
                storeId: (int) $billing->store_id,
                customerId: (int) $billing->customer_id,
                billingId: (int) $billing->billing_id,
                pointsToRedeem: $pointsToRedeem,
            );

            $pointsDiscount = (float) $redemption['discount_amount'];

            $billing->update([
                'total' => max((float) $billing->total - $pointsDiscount, 0),
                'balance_due' => max((float) $billing->balance_due - $pointsDiscount, 0),
                'points_discount' => $pointsDiscount,
            ]);
        } catch (\Throwable $e) {
            $this->abort422($e->getMessage());
        }
    }

    private function completeZeroBalanceBilling(Billing $billing, string $paymentMethod): Payment
    {
        $billing->update([
            'paid_amount' => (float) $billing->total,
            'balance_due' => 0,
            'status' => 'paid',
            'is_draft' => false,
            'stock_applied_at' => $billing->stock_applied_at ?: now(),
        ]);

        $payment = Payment::create([
            'billing_id' => $billing->billing_id,
            'receiptnumber' => $this->documentNumberService->nextNumber($billing->store_id, 'Receipt'),
            'payment_method' => $paymentMethod,
            'amount_received' => 0,
            'amount_tendered' => 0,
            'change_returned' => 0,
            'balance_before' => 0,
            'balance_after' => 0,
            'payment_date' => now(),
            'status' => 'paid',
            'cheque_status' => $paymentMethod === 'cheque' ? 'cleared' : null,
        ]);

        if (!empty($billing->customer_id)) {
            $freshBalance = round((float) Billing::where('customer_id', $billing->customer_id)
                ->where('status', '!=', 'paid')
                ->where('is_draft', false)
                ->sum('balance_due'), 2);

            Customer::where('customer_id', $billing->customer_id)
                ->update(['current_balance' => $freshBalance]);
        }

        $this->applyPostPaymentLoyalty($billing->fresh());

        return $payment->load(['billing.customer', 'billing.store', 'billing.user']);
    }

    private function loadOpenBillingsForAllocation(Billing $billing, ?int $customerId): array
    {
        if (empty($customerId)) {
            return [collect([$billing]), 0.0];
        }

        $previousDebtsSum = round((float) Billing::where('customer_id', $customerId)
            ->where('billing_id', '!=', $billing->billing_id)
            ->where('status', '!=', 'paid')
            ->where('is_draft', false)
            ->sum('balance_due'), 2);

        $openBillings = Billing::where('customer_id', $customerId)
            ->where('status', '!=', 'paid')
            ->where('is_draft', false)
            ->lockForUpdate()
            ->orderBy('created_at')
            ->get();

        return [$openBillings, $previousDebtsSum];
    }

    private function normalisePaymentAllocations(array $data): array
    {
        if (!empty($data['payment_allocations']) && is_array($data['payment_allocations'])) {
            return array_values(array_filter(array_map(
                fn (array $row) => $this->normaliseAllocationRow($row),
                $data['payment_allocations']
            )));
        }

        if (empty($data['payment_method'])) {
            return [];
        }

        return [$this->normaliseAllocationRow([
            'payment_method' => $data['payment_method'],
            'amount_received' => $data['amount_received'] ?? 0,
            'amount_tendered' => $data['amount_tendered'] ?? null,
            'mpesa_phone' => $data['mpesa_phone'] ?? null,
            'mpesa_code' => $data['mpesa_code'] ?? null,
            'mpesa_mode' => $data['mpesa_mode'] ?? null,
            'card_reference' => $data['card_reference'] ?? null,
            'card_holder' => $data['card_holder'] ?? null,
            'cheque_bank_name' => $data['cheque_bank_name'] ?? null,
            'cheque_bank_code' => $data['cheque_bank_code'] ?? null,
            'cheque_number' => $data['cheque_number'] ?? null,
            'cheque_date' => $data['cheque_date'] ?? null,
            'cheque_account_name' => $data['cheque_account_name'] ?? null,
            'cheque_account_number' => $data['cheque_account_number'] ?? null,
            'cheque_branch_name' => $data['cheque_branch_name'] ?? null,
            'cheque_notes' => $data['cheque_notes'] ?? null,
        ])];
    }

    private function normaliseAllocationRow(array $row): array
    {
        $method = strtolower(trim((string) ($row['payment_method'] ?? '')));
        if (!in_array($method, ['cash', 'mpesa', 'card', 'cheque'], true)) {
            $this->abort422('Unsupported payment method in split allocation.');
        }

        $amountReceived = round((float) ($row['amount_received'] ?? 0), 2);
        if ($amountReceived <= 0) {
            $this->abort422('Each payment allocation must be greater than zero.');
        }

        $amountTendered = array_key_exists('amount_tendered', $row) && $row['amount_tendered'] !== null
            ? round((float) $row['amount_tendered'], 2)
            : $amountReceived;

        $mpesaMode = $method === 'mpesa'
            ? strtolower(trim((string) ($row['mpesa_mode'] ?? 'manual')))
            : null;

        $normalized = [
            'payment_method' => $method,
            'amount_received' => $amountReceived,
            'amount_tendered' => $amountTendered,
            'mpesa_phone' => $method === 'mpesa' ? trim((string) ($row['mpesa_phone'] ?? '')) : null,
            'mpesa_code' => $method === 'mpesa' ? strtoupper(trim((string) ($row['mpesa_code'] ?? ''))) : null,
            'mpesa_mode' => $mpesaMode,
            'card_reference' => $method === 'card' ? trim((string) ($row['card_reference'] ?? '')) : null,
            'card_holder' => $method === 'card' ? trim((string) ($row['card_holder'] ?? '')) : null,
            'cheque_bank_name' => $method === 'cheque' ? trim((string) ($row['cheque_bank_name'] ?? '')) : null,
            'cheque_bank_code' => $method === 'cheque' ? (($code = strtoupper(trim((string) ($row['cheque_bank_code'] ?? '')))) !== '' ? $code : null) : null,
            'cheque_number' => $method === 'cheque' ? trim((string) ($row['cheque_number'] ?? '')) : null,
            'cheque_date' => $method === 'cheque' ? ($row['cheque_date'] ?? null) : null,
            'cheque_account_name' => $method === 'cheque' ? (trim((string) ($row['cheque_account_name'] ?? '')) ?: null) : null,
            'cheque_account_number' => $method === 'cheque' ? (trim((string) ($row['cheque_account_number'] ?? '')) ?: null) : null,
            'cheque_branch_name' => $method === 'cheque' ? (trim((string) ($row['cheque_branch_name'] ?? '')) ?: null) : null,
            'cheque_notes' => $method === 'cheque' ? (trim((string) ($row['cheque_notes'] ?? '')) ?: null) : null,
        ];

        if ($method === 'cash' && $amountTendered < $amountReceived) {
            $this->abort422('Cash tendered cannot be less than the allocated cash amount.');
        }

        if ($method === 'mpesa') {
            if ($normalized['mpesa_phone'] === '') {
                $this->abort422('Every M-Pesa allocation requires a phone number.');
            }
            if (!in_array($mpesaMode, ['stk', 'manual'], true)) {
                $this->abort422('Every M-Pesa allocation must declare stk or manual mode.');
            }
            if ($mpesaMode === 'manual' && $normalized['mpesa_code'] === '') {
                $this->abort422('Manual M-Pesa allocations require a transaction code.');
            }
        }

        if ($method === 'card' && $normalized['card_reference'] === '') {
            $this->abort422('Every card allocation requires a card reference.');
        }

        if ($method === 'cheque') {
            if ($normalized['cheque_bank_name'] === '' && empty($normalized['cheque_bank_code'])) {
                $this->abort422('Every cheque allocation requires either a bank name or a bank code.');
            }
            if ($normalized['cheque_bank_name'] !== '' && !empty($normalized['cheque_bank_code'])) {
                $this->abort422('Every cheque allocation must use cheque bank name or bank code, not both.');
            }
            if ($normalized['cheque_number'] === '') {
                $this->abort422('Every cheque allocation requires a cheque number.');
            }
            if (empty($normalized['cheque_date'])) {
                $this->abort422('Every cheque allocation requires the cheque date.');
            }
        }

        return $normalized;
    }

    private function applyAllocations(Billing $billing, Collection $openBillings, array $allocations): ?Payment
    {
        $primaryPaymentRecord = null;

        foreach ($allocations as $allocation) {
            $allocationAmountLeft = round((float) $allocation['amount_received'], 2);
            $tenderedForAllocation = round((float) ($allocation['amount_tendered'] ?? $allocation['amount_received']), 2);
            $changeForAllocation = $allocation['payment_method'] === 'cash'
                ? round(max($tenderedForAllocation - (float) $allocation['amount_received'], 0), 2)
                : 0.0;
            $tenderedAssigned = false;
            $changeAssigned = false;

            foreach ($openBillings as $currentBill) {
                if ($allocationAmountLeft <= 0) {
                    break;
                }

                $billBalanceBefore = round((float) $currentBill->balance_due, 2);
                if ($billBalanceBefore <= 0) {
                    continue;
                }

                $paymentToApply = round(min($allocationAmountLeft, $billBalanceBefore), 2);
                if ($paymentToApply <= 0) {
                    continue;
                }

                $payment = Payment::create([
                    'billing_id' => $currentBill->billing_id,
                    'receiptnumber' => $this->documentNumberService->nextNumber($currentBill->store_id, 'Receipt'),
                    'payment_method' => $allocation['payment_method'],
                    'amount_received' => $paymentToApply,
                    'amount_tendered' => $tenderedAssigned ? $paymentToApply : $tenderedForAllocation,
                    'change_returned' => $changeAssigned ? 0.00 : $changeForAllocation,
                    'balance_before' => $billBalanceBefore,
                    'balance_after' => round(max($billBalanceBefore - $paymentToApply, 0), 2),
                    'payment_date' => now(),
                    'status' => $allocation['payment_method'] === 'cheque' ? 'pending' : 'paid',
                    'mpesa_phone' => $allocation['mpesa_phone'] ?? null,
                    'mpesa_receipt' => $allocation['mpesa_code'] ?? null,
                    'mpesa_mode' => $allocation['mpesa_mode'] ?? null,
                    'card_reference' => $allocation['card_reference'] ?? null,
                    'card_holder' => $allocation['card_holder'] ?? null,
                    'cheque_bank_name' => $allocation['cheque_bank_name'] ?? null,
                    'cheque_bank_code' => $allocation['cheque_bank_code'] ?? null,
                    'cheque_number' => $allocation['cheque_number'] ?? null,
                    'cheque_date' => $allocation['cheque_date'] ?? null,
                    'cheque_account_name' => $allocation['cheque_account_name'] ?? null,
                    'cheque_account_number' => $allocation['cheque_account_number'] ?? null,
                    'cheque_branch_name' => $allocation['cheque_branch_name'] ?? null,
                    'cheque_notes' => $allocation['cheque_notes'] ?? null,
                    'cheque_status' => $allocation['payment_method'] === 'cheque' ? 'pending' : null,
                ]);

                if ((int) $currentBill->billing_id === (int) $billing->billing_id && $primaryPaymentRecord === null) {
                    $primaryPaymentRecord = $payment;
                } elseif ($primaryPaymentRecord === null) {
                    $primaryPaymentRecord = $payment;
                }

                $currentBill = $this->syncBillingSettlementState($currentBill);

                $this->auditLogService->log(
                    'payment.create',
                    $payment,
                    null,
                    $payment->toArray(),
                    [
                        'billing_uuid' => $currentBill->uuid,
                        'billing_id' => $currentBill->billing_id,
                        'invoice_number' => $currentBill->invnumber,
                        'payment_method' => $allocation['payment_method'],
                    ],
                    $currentBill->store_id
                );

                $allocationAmountLeft = round($allocationAmountLeft - $paymentToApply, 2);
                $tenderedAssigned = true;
                $changeAssigned = true;
            }

            if ($allocationAmountLeft > 0.01) {
                $this->abort422('A payment allocation exceeded the available outstanding balance.');
            }
        }

        return $primaryPaymentRecord;
    }

    private function applyPostPaymentLoyalty(Billing $billing): void
    {
        if (empty($billing->customer_id)) {
            return;
        }

        try {
            $this->loyaltyService->earnPoints(
                storeId: (int) $billing->store_id,
                customerId: (int) $billing->customer_id,
                billingId: (int) $billing->billing_id,
                saleAmount: (float) $billing->total,
            );
        } catch (\Throwable $e) {
            \Log::warning('Loyalty points earn failed: ' . $e->getMessage());
        }

        try {
            $billing->loadMissing('items.product');
            $rule = $this->loyaltyService->getActiveRule((int) $billing->store_id);

            if ($rule && $rule->chapa5_enabled && filled($rule->chapa5_product_sku)) {
                $promoSku = strtolower(trim((string) $rule->chapa5_product_sku));

                $qualifyingQty = (int) $billing->items
                    ->filter(function ($item) use ($promoSku) {
                        $itemSku = strtolower(trim((string) data_get($item, 'product.sku')));
                        $qty = (int) ($item->quantity ?? 0);
                        $unitPrice = (float) ($item->unit_price ?? 0);
                        $lineTotal = (float) ($item->total_amount ?? ($qty * $unitPrice));

                        return $itemSku !== ''
                            && $itemSku === $promoSku
                            && $qty > 0
                            && $unitPrice > 0
                            && $lineTotal > 0;
                    })
                    ->sum(function ($item) {
                        return (int) ($item->quantity ?? 0);
                    });

                if ($qualifyingQty > 0) {
                    $this->loyaltyService->applyChapa5(
                        storeId: (int) $billing->store_id,
                        customerId: (int) $billing->customer_id,
                        billingId: (int) $billing->billing_id,
                        sku: $rule->chapa5_product_sku,
                        itemsBought: (int) $qualifyingQty,
                    );
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Chapa 5 apply failed: ' . $e->getMessage());
        }
    }

    private function abort422(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
        ], 422));
    }
}
