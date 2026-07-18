<?php

namespace App\Services\Mpesa;

use App\Events\BillingPaymentSettled;
use App\Events\PaymentClaimRequested;
use App\Events\PaymentClaimResolved;
use App\Events\UnassignedPaymentCreated;
use App\Models\ActivePaymentAttempt;
use App\Models\Billing;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\Store;
use App\Models\UnassignedMpesaPayment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RealtimeC2BPaymentService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly MpesaService $mpesaService,
    ) {
    }

    public function registerC2BUrlsForStore(Store $store): array
    {
        $creds = $this->mpesaService->resolveCredentialsForStore($store);
        $client = new DarajaClient($creds);

        $base = rtrim($creds['callback_base_url'] ?: config('app.url'), '/');
        $token = config('mpesa.callback_shared_secret');
        $query = $token ? '?token=' . urlencode($token) : '';

        $response = $client->c2bRegisterUrls(
            $base . config('mpesa.callback_paths.c2b_validation') . $query,
            $base . config('mpesa.callback_paths.c2b_confirmation') . $query,
        );

        if ($this->c2bRegistrationLooksSuccessful($response)) {
            Cache::put($this->c2bRegistrationCacheKey($creds), true, now()->addDays(30));
        }

        return $response;
    }

    public function startWaitingAttempt(Billing $billing, User $user, string $terminalId, float $expectedAmount, array $splitAllocations = [], int $pointsRedeemed = 0): ActivePaymentAttempt
    {
        if ($billing->status === 'paid') {
            throw new RuntimeException('This bill is already paid.');
        }

        $this->ensureRealtimeCallbacksReady($billing);

        return DB::transaction(function () use ($billing, $user, $terminalId, $expectedAmount, $splitAllocations, $pointsRedeemed) {
            ActivePaymentAttempt::query()
                ->where('terminal_id', $terminalId)
                ->whereIn('status', ['WAITING_FOR_PAYMENT', 'CLAIM_REQUESTED'])
                ->update([
                    'status' => 'CANCELLED',
                    'cancelled_at' => now(),
                ]);

            ActivePaymentAttempt::query()
                ->where('billing_id', $billing->billing_id)
                ->whereIn('status', ['WAITING_FOR_PAYMENT', 'CLAIM_REQUESTED'])
                ->update([
                    'status' => 'CANCELLED',
                    'cancelled_at' => now(),
                ]);

            return ActivePaymentAttempt::create([
                'store_id' => $billing->store_id,
                'billing_id' => $billing->billing_id,
                'user_id' => $user->user_id,
                'terminal_id' => $terminalId,
                'expected_amount' => round($expectedAmount, 2),
                'status' => 'WAITING_FOR_PAYMENT',
                'initiated_at' => now(),
                'expires_at' => now()->addMinutes((int) config('mpesa.realtime.wait_ttl_minutes', 5)),
                'split_allocations' => array_values($splitAllocations),
                'points_redeemed' => max(0, $pointsRedeemed),
                'meta' => [
                    'bill_channel' => 'bill.' . $billing->billing_id,
                    'store_id' => $billing->store_id,
                ],
            ]);
        });
    }

    public function cancelWaitingAttempt(ActivePaymentAttempt $attempt, User $user): ActivePaymentAttempt
    {
        // Optional ownership check can be tightened with your existing RBAC layer.

        $attempt->update([
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
        ]);

        return $attempt->fresh();
    }

    private function ensureRealtimeCallbacksReady(Billing $billing): void
    {
        try {
            $store = $billing->store()->first();
            if (! $store) {
                return;
            }

            $creds = $this->mpesaService->resolveCredentialsForStore($store);
            $cacheKey = $this->c2bRegistrationCacheKey($creds);

            if (Cache::get($cacheKey)) {
                return;
            }

            $response = $this->registerC2BUrlsForStore($store);
            if ($this->c2bRegistrationLooksSuccessful($response)) {
                Cache::put($cacheKey, true, now()->addDays(30));
            }
        } catch (\Throwable $e) {
            Log::warning('[Mpesa Realtime] Unable to auto-register C2B URLs', [
                'billing_id' => $billing->billing_id,
                'store_id' => $billing->store_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function c2bRegistrationCacheKey(array $creds): string
    {
        return 'mpesa:c2b:registered:' . md5((string) ($creds['environment'] ?? '') . '|' . (string) ($creds['shortcode'] ?? ''));
    }

    private function c2bRegistrationLooksSuccessful(array $response): bool
    {
        $blob = strtolower(json_encode($response));

        return isset($response['ConversationID'])
            || isset($response['OriginatorConversationID'])
            || (($response['ResponseCode'] ?? null) === '0')
            || str_contains($blob, 'success')
            || str_contains($blob, 'accepted')
            || str_contains($blob, 'already');
    }

    public function listUnassignedPayments(int $storeId)
    {
        return UnassignedMpesaPayment::query()
            ->where('store_id', $storeId)
            ->where('status', 'UNASSIGNED')
            ->latest('unassigned_mpesa_payment_id')
            ->get();
    }

    public function processIncomingConfirmation(array $payload): MpesaTransaction
    {
        $receipt = strtoupper(trim((string) data_get($payload, 'TransID')));
        if ($receipt === '') {
            throw new RuntimeException('Missing TransID in C2B confirmation payload.');
        }

        return DB::transaction(function () use ($payload, $receipt) {
            $existing = MpesaTransaction::query()
                ->where('mpesa_receipt', $receipt)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                Log::info('[Mpesa Realtime] Duplicate confirmation ignored', ['receipt' => $receipt]);
                return $existing;
            }

            $transactionTime = $this->parseMpesaTimestamp(data_get($payload, 'TransTime'));
            $amount = round((float) data_get($payload, 'TransAmount', 0), 2);
            $billRef = trim((string) data_get($payload, 'BillRefNumber', ''));
            $phone = $this->normalizeLoosePhoneForMatching((string) data_get($payload, 'MSISDN', ''))
                ?: (string) data_get($payload, 'MSISDN', '');
            $shortcode = (string) data_get($payload, 'BusinessShortCode', '');

            $billing = $this->resolveBillingByExactReference($billRef);
            $store = $billing?->store_id ? Store::query()->find($billing->store_id) : $this->resolveStore($shortcode, $billing);

            $txn = MpesaTransaction::create([
                'store_id' => $store?->store_id,
                'billing_id' => $billing?->billing_id,
                'user_id' => $billing?->user_id,
                'channel' => 'c2b',
                'shortcode_type' => str_contains(strtolower((string) data_get($payload, 'TransactionType')), 'buy') ? 'till' : 'paybill',
                'mpesa_receipt' => $receipt,
                'amount' => $amount,
                'phone_number' => $phone,
                'account_reference' => $billRef,
                'transaction_desc' => data_get($payload, 'TransactionType', 'C2B'),
                'transaction_date' => $transactionTime,
                'status' => 'received',
                'result_code' => '0',
                'result_desc' => 'Confirmation received successfully',
                'callback_payload' => $payload,
                'environment' => config('mpesa.environment', 'sandbox'),
            ]);

            if ($billing && $this->isBillingPayable($billing)) {
                return $this->settleMatchedBilling($txn, $billing, null, 'EXACT_ACCOUNT_MATCH');
            }

            $windowStart = $transactionTime->copy()->subMinutes(5);
            $candidateBilling = $this->findTemporalValueMatches($store?->store_id, $amount, $windowStart, $transactionTime);
            $activeAttempts = $this->findActiveAttempts($store?->store_id, $amount, $windowStart, $transactionTime);

            if ($activeAttempts->count() === 1) {
                $attempt = $activeAttempts->first();
                $attemptBilling = Billing::query()->find($attempt->billing_id);
                if ($attemptBilling && $this->isBillingPayable($attemptBilling)) {
                    return $this->settleMatchedBilling($txn, $attemptBilling, $attempt, 'ACTIVE_CHECKOUT_SINGLE_MATCH');
                }
            }

            if ($candidateBilling->count() === 1) {
                return $this->settleMatchedBilling($txn, $candidateBilling->first(), null, 'TEMPORAL_VALUE_SINGLE_MATCH');
            }

            if ($activeAttempts->count() > 1 || $candidateBilling->count() > 1) {
                return $this->flagConflictAndBroadcast($txn, $candidateBilling->all(), $activeAttempts->all());
            }

            return $this->routeToUnassigned($txn, [], [], false);
        });
    }

    public function claimConflictPayment(MpesaTransaction $txn, User $user, string $terminalId): MpesaTransaction
    {
        return DB::transaction(function () use ($txn, $user, $terminalId) {
            $txn = MpesaTransaction::query()->lockForUpdate()->findOrFail($txn->mpesa_transaction_id);
            if ($txn->payment_id) {
                return $txn;
            }

            $attempt = ActivePaymentAttempt::query()
                ->lockForUpdate()
                ->where('terminal_id', $terminalId)
                ->where('expected_amount', round((float) $txn->amount, 2))
                ->whereIn('status', ['WAITING_FOR_PAYMENT', 'CLAIM_REQUESTED'])
                ->latest('active_payment_attempt_id')
                ->first();

            if (!$attempt) {
                throw new RuntimeException('No matching active checkout was found for this terminal.');
            }

            $billing = Billing::query()->findOrFail($attempt->billing_id);
            $resolved = $this->settleMatchedBilling($txn, $billing, $attempt, 'CLAIMED_BY_CASHIER', $user);

            ActivePaymentAttempt::query()
                ->where('expected_amount', round((float) $txn->amount, 2))
                ->where('active_payment_attempt_id', '!=', $attempt->active_payment_attempt_id)
                ->whereIn('status', ['WAITING_FOR_PAYMENT', 'CLAIM_REQUESTED'])
                ->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);

            $this->resolveUnassignedRecord($txn, $terminalId, $billing->billing_id, $user->user_id);

            foreach ($this->terminalsForSameAmount($txn, $attempt->active_payment_attempt_id) as $otherTerminalId) {
                event(new PaymentClaimResolved($otherTerminalId, [
                    'mpesa_transaction_id' => $txn->mpesa_transaction_id,
                    'status' => 'CLAIMED_ON_ANOTHER_TERMINAL',
                    'claimed_terminal_id' => $terminalId,
                    'billing_id' => $billing->billing_id,
                ]));
            }

            return $resolved;
        });
    }

    public function applyUnassignedPayment(UnassignedMpesaPayment $unassigned, Billing $billing, User $user, string $terminalId): MpesaTransaction
    {
        return DB::transaction(function () use ($unassigned, $billing, $user, $terminalId) {
            $unassigned = UnassignedMpesaPayment::query()->lockForUpdate()->findOrFail($unassigned->unassigned_mpesa_payment_id);
            if ($unassigned->status !== 'UNASSIGNED') {
                throw new RuntimeException('This payment has already been assigned.');
            }

            $txn = MpesaTransaction::query()->lockForUpdate()->findOrFail($unassigned->mpesa_transaction_id);
            $attempt = ActivePaymentAttempt::query()
                ->where('terminal_id', $terminalId)
                ->where('billing_id', $billing->billing_id)
                ->latest('active_payment_attempt_id')
                ->first();

            $resolved = $this->settleMatchedBilling($txn, $billing, $attempt, 'UNASSIGNED_APPLIED', $user);
            $this->resolveUnassignedRecord($txn, $terminalId, $billing->billing_id, $user->user_id);

            return $resolved;
        });
    }

    private function settleMatchedBilling(MpesaTransaction $txn, Billing $billing, ?ActivePaymentAttempt $attempt, string $matchMode, ?User $actingUser = null): MpesaTransaction
    {
        if ($txn->payment_id) {
            return $txn;
        }

        $billing = Billing::query()->lockForUpdate()->findOrFail($billing->billing_id);
        $user = $actingUser
            ?? ($attempt?->user_id ? User::query()->find($attempt->user_id) : null)
            ?? ($billing->user_id ? User::query()->find($billing->user_id) : null)
            ?? User::query()->where('store_id', $billing->store_id)->first();

        if (!$user) {
            throw new RuntimeException('Unable to determine the cashier context for settlement.');
        }

        if ($billing->status !== 'paid') {
            $splitAllocations = (array) ($attempt?->split_allocations ?? []);
            $pointsRedeemed = (int) ($attempt?->points_redeemed ?? 0);
            $paymentPayload = !empty($splitAllocations)
                ? [
                    'payment_allocations' => $this->materializeAllocations($txn, $splitAllocations),
                    'points_redeemed' => $pointsRedeemed,
                  ]
                : [
                    'payment_method' => 'mpesa',
                    'amount_received' => (float) $txn->amount,
                    'amount_tendered' => (float) $txn->amount,
                    'mpesa_phone' => $txn->phone_number,
                    'mpesa_code' => $txn->mpesa_receipt,
                  ];

            $payment = $this->paymentService->charge($billing, $user, $paymentPayload);
            $paymentId = $payment->payment_id ?? Payment::query()
                ->where('billing_id', $billing->billing_id)
                ->latest('payment_id')
                ->value('payment_id');

            if ($paymentId) {
                Payment::query()->where('payment_id', $paymentId)->update([
                    'mpesa_receipt' => $txn->mpesa_receipt,
                    'mpesa_phone' => $txn->phone_number,
                    'mpesa_transaction_id' => $txn->mpesa_transaction_id,
                ]);
            }

            $txn->update([
                'store_id' => $billing->store_id,
                'billing_id' => $billing->billing_id,
                'user_id' => $user->user_id,
                'payment_id' => $paymentId,
                'status' => 'success',
                'result_code' => '0',
                'result_desc' => 'Confirmation received successfully',
                'request_payload' => [
                    ...((array) $txn->request_payload),
                    'match_mode' => $matchMode,
                ],
            ]);
        }

        if ($attempt) {
            $attempt->update([
                'status' => 'SETTLED',
                'claimed_at' => $attempt->claimed_at ?: now(),
                'settled_at' => now(),
            ]);
        }

        $billing->update(['matching_status' => 'MATCHED']);

        event(new BillingPaymentSettled([
            'billing_id' => $billing->billing_id,
            'payment_id' => $txn->payment_id,
            'mpesa_transaction_id' => $txn->mpesa_transaction_id,
            'mpesa_receipt' => $txn->mpesa_receipt,
            'phone_number' => $txn->phone_number,
            'amount' => (float) $txn->amount,
            'payment_status' => 'PAID',
            'payment_method' => 'mpesa',
            'match_mode' => $matchMode,
        ]));

        return $txn->fresh();
    }

    private function flagConflictAndBroadcast(MpesaTransaction $txn, array $candidateBilling, array $attempts): MpesaTransaction
    {
        $billingIds = array_values(array_unique(array_filter(array_map(fn ($billing) => $billing?->billing_id, $candidateBilling))));
        $attemptIds = array_values(array_unique(array_filter(array_map(fn ($attempt) => $attempt?->active_payment_attempt_id, $attempts))));

        if (!empty($billingIds)) {
            Billing::query()->whereIn('billing_id', $billingIds)->update(['matching_status' => 'CONFLICT_FLAGGED']);
        }

        if (!empty($attemptIds)) {
            ActivePaymentAttempt::query()
                ->whereIn('active_payment_attempt_id', $attemptIds)
                ->update(['status' => 'CLAIM_REQUESTED']);
        }

        $txn->update([
            'status' => 'conflict',
            'result_desc' => 'Multiple possible matching bills detected',
            'request_payload' => [
                ...((array) $txn->request_payload),
                'candidate_billing_ids' => $billingIds,
                'candidate_attempt_ids' => $attemptIds,
            ],
        ]);

        $unassigned = $this->createUnassignedRecord($txn, $billingIds, $attemptIds, true);

        foreach ($attempts as $attempt) {
            event(new PaymentClaimRequested($attempt->terminal_id, [
                'unassigned_mpesa_payment_id' => $unassigned->unassigned_mpesa_payment_id,
                'mpesa_transaction_id' => $txn->mpesa_transaction_id,
                'billing_id' => $attempt->billing_id,
                'active_payment_attempt_id' => $attempt->active_payment_attempt_id,
                'amount' => (float) $txn->amount,
                'phone_number' => $txn->phone_number,
                'customer_name' => $this->customerNameFromPayload($txn->callback_payload),
                'mpesa_receipt' => $txn->mpesa_receipt,
                'message' => 'A payment with the same amount was detected. Claim it if it belongs to your customer.',
            ]));
        }

        event(new UnassignedPaymentCreated((int) $txn->store_id, $this->unassignedPayload($unassigned, $txn)));

        return $txn->fresh();
    }

    private function routeToUnassigned(MpesaTransaction $txn, array $candidateBilling, array $attempts, bool $conflictFlagged): MpesaTransaction
    {
        $billingIds = array_values(array_unique(array_filter(array_map(fn ($billing) => $billing?->billing_id, $candidateBilling))));
        $attemptIds = array_values(array_unique(array_filter(array_map(fn ($attempt) => $attempt?->active_payment_attempt_id, $attempts))));
        $txn->update([
            'status' => 'unassigned',
            'result_desc' => 'Payment awaiting manual cashier pairing',
        ]);

        $unassigned = $this->createUnassignedRecord($txn, $billingIds, $attemptIds, $conflictFlagged);
        event(new UnassignedPaymentCreated((int) $txn->store_id, $this->unassignedPayload($unassigned, $txn)));

        return $txn->fresh();
    }

    private function createUnassignedRecord(MpesaTransaction $txn, array $billingIds, array $attemptIds, bool $conflictFlagged): UnassignedMpesaPayment
    {
        return UnassignedMpesaPayment::query()->updateOrCreate(
            ['mpesa_transaction_id' => $txn->mpesa_transaction_id],
            [
                'store_id' => $txn->store_id,
                'amount' => (float) $txn->amount,
                'phone_number' => $txn->phone_number,
                'customer_name' => $this->customerNameFromPayload($txn->callback_payload),
                'bill_ref_number' => $txn->account_reference,
                'status' => 'UNASSIGNED',
                'conflict_flagged' => $conflictFlagged,
                'candidate_billing_ids' => $billingIds,
                'candidate_attempt_ids' => $attemptIds,
                'payload' => $txn->callback_payload,
            ]
        );
    }

    private function resolveUnassignedRecord(MpesaTransaction $txn, string $terminalId, int $billingId, int $userId): void
    {
        UnassignedMpesaPayment::query()
            ->where('mpesa_transaction_id', $txn->mpesa_transaction_id)
            ->update([
                'status' => 'ASSIGNED',
                'claimed_terminal_id' => $terminalId,
                'claimed_billing_id' => $billingId,
                'claimed_by_user_id' => $userId,
                'resolved_at' => now(),
            ]);
    }

    private function resolveBillingByExactReference(string $billRef): ?Billing
    {
        if ($billRef === '') {
            return null;
        }

        return Billing::query()
            ->where(function ($query) use ($billRef) {
                $query->where('invnumber', $billRef)
                    ->orWhere('uuid', $billRef)
                    ->orWhere('billing_id', $billRef);
            })
            ->latest('billing_id')
            ->first();
    }

    private function resolveStore(?string $shortcode, ?Billing $billing): ?Store
    {
        if ($billing?->store_id) {
            return Store::query()->find($billing->store_id);
        }

        if (!$shortcode) {
            return null;
        }

        return Store::query()
            ->where(function ($query) use ($shortcode) {
                $query->where('mpesa_shortcode', $shortcode)
                    ->orWhere('mpesa_till_number', $shortcode);
            })
            ->first();
    }

    private function findTemporalValueMatches(?int $storeId, float $amount, Carbon $windowStart, Carbon $transactionTime)
    {
        $lookBackMinutes = max(10, ((int) config('mpesa.realtime.wait_ttl_minutes', 5)) + 10);
        $searchStart = $windowStart->copy()->subMinutes($lookBackMinutes);
        $searchEnd = $transactionTime->copy()->addMinutes(2);

        return Billing::query()
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->whereBetween('created_at', [$searchStart, $searchEnd])
            ->where(function ($query) use ($amount) {
                $query->whereRaw('ABS(COALESCE(balance_due, total, 0) - ?) <= 0.01', [$amount])
                    ->orWhereRaw('ABS(COALESCE(total, balance_due, 0) - ?) <= 0.01', [$amount]);
            })
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'paid');
            })
            ->latest('billing_id')
            ->get();
    }

    private function findActiveAttempts(?int $storeId, float $amount, Carbon $windowStart, Carbon $transactionTime)
    {
        return ActivePaymentAttempt::query()
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->whereIn('status', ['WAITING_FOR_PAYMENT', 'CLAIM_REQUESTED'])
            ->where(function ($query) use ($transactionTime) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now()->subSeconds(30))
                    ->orWhere('expires_at', '>=', $transactionTime);
            })
            ->where('initiated_at', '>=', now()->subMinutes(max(15, ((int) config('mpesa.realtime.wait_ttl_minutes', 5)) + 15)))
            ->whereRaw('ABS(expected_amount - ?) <= 0.01', [$amount])
            ->latest('active_payment_attempt_id')
            ->get();
    }

    private function terminalsForSameAmount(MpesaTransaction $txn, int $exceptAttemptId): array
    {
        return ActivePaymentAttempt::query()
            ->when($txn->store_id, fn ($query) => $query->where('store_id', $txn->store_id))
            ->where('active_payment_attempt_id', '!=', $exceptAttemptId)
            ->whereIn('status', ['WAITING_FOR_PAYMENT', 'CLAIM_REQUESTED'])
            ->whereRaw('ABS(expected_amount - ?) <= 0.01', [(float) $txn->amount])
            ->pluck('terminal_id')
            ->filter()
            ->values()
            ->all();
    }

    private function parseMpesaTimestamp(?string $value): Carbon
    {
        if (!$value) {
            return now();
        }

        try {
            return Carbon::createFromFormat('YmdHis', $value);
        } catch (\Throwable) {
            return now();
        }
    }

    private function isBillingPayable(Billing $billing): bool
    {
        return strtolower((string) ($billing->status ?? 'pending')) !== 'paid';
    }

    private function materializeAllocations(MpesaTransaction $txn, array $splitAllocations): array
    {
        return array_map(function (array $row) use ($txn) {
            $method = strtolower((string) Arr::get($row, 'payment_method'));
            $mode = strtolower((string) Arr::get($row, 'mpesa_mode'));

            if ($method === 'mpesa' && in_array($mode, ['till', 'manual', 'stk'], true)) {
                return [
                    ...$row,
                    'payment_method' => 'mpesa',
                    'amount_received' => (float) Arr::get($row, 'amount_received', $txn->amount),
                    'amount_tendered' => (float) Arr::get($row, 'amount_tendered', Arr::get($row, 'amount_received', $txn->amount)),
                    'mpesa_phone' => $txn->phone_number,
                    'mpesa_code' => $txn->mpesa_receipt,
                    'mpesa_mode' => 'manual',
                ];
            }

            return $row;
        }, $splitAllocations);
    }

    private function customerNameFromPayload(?array $payload): ?string
    {
        $parts = array_filter([
            data_get($payload, 'FirstName'),
            data_get($payload, 'MiddleName'),
            data_get($payload, 'LastName'),
        ]);

        return $parts ? implode(' ', $parts) : null;
    }

    private function unassignedPayload(UnassignedMpesaPayment $unassigned, MpesaTransaction $txn): array
    {
        return [
            'unassigned_mpesa_payment_id' => $unassigned->unassigned_mpesa_payment_id,
            'mpesa_transaction_id' => $txn->mpesa_transaction_id,
            'mpesa_receipt' => $txn->mpesa_receipt,
            'amount' => (float) $txn->amount,
            'phone_number' => $txn->phone_number,
            'customer_name' => $unassigned->customer_name,
            'bill_ref_number' => $txn->account_reference,
            'conflict_flagged' => (bool) $unassigned->conflict_flagged,
            'status' => $unassigned->status,
            'candidate_billing_ids' => $unassigned->candidate_billing_ids,
            'candidate_attempt_ids' => $unassigned->candidate_attempt_ids,
        ];
    }
}
