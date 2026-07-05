<?php

namespace App\Services\Mpesa;

use App\Models\Billing;
use App\Models\Grn;
use App\Models\MpesaTransaction;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PaymentService;
use App\Services\GrnService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * MpesaService — orchestrates the M-Pesa payment lifecycle.
 *
 * Public API:
 *   - initiateStkPush(Billing, User, phone, amount) : MpesaTransaction
 *   - handleStkCallback(array payload) : MpesaTransaction
 *   - handleC2bConfirmation(array payload) : MpesaTransaction
 *   - status(string checkoutRequestId) : array
 *   - validateManualReceipt(string receipt, Billing, User, amount) : MpesaTransaction
 *
 * Double-charge prevention:
 *   Every initiate call takes a Redis/DB lock on `mpesa:lock:billing:{id}`.
 *   If a pending transaction already exists for that billing within the
 *   idempotency TTL, we RETURN THAT EXISTING TRANSACTION instead of pushing
 *   again — so the cashier hitting "Charge" twice never triggers two STKs.
 */
class MpesaService
{
    public function __construct(
        private readonly PaymentService  $paymentService,
        private readonly AuditLogService $auditLogService,
        private readonly GrnService      $grnService,
    ) {}

    // ─────────────────────────────────────────────────────────────
    //  Credential resolution — .env defaults + per-store overrides
    // ─────────────────────────────────────────────────────────────

    public function resolveCredentialsForStore(Store $store): array
    {
        $decrypt = static function (?string $v): ?string {
            if (!$v) return null;
            try { return decrypt($v); } catch (\Throwable) { return $v; }
        };

        return [
            'consumer_key'     => $decrypt($store->mpesa_consumer_key)    ?? config('mpesa.consumer_key'),
            'consumer_secret'  => $decrypt($store->mpesa_consumer_secret) ?? config('mpesa.consumer_secret'),
            'passkey'          => $decrypt($store->mpesa_passkey)         ?? config('mpesa.passkey'),
            'shortcode'        => $store->mpesa_shortcode                 ?? config('mpesa.shortcode'),
            'shortcode_type'   => $store->mpesa_shortcode_type            ?? config('mpesa.shortcode_type', 'paybill'),
            'till_number'      => $store->mpesa_till_number               ?? config('mpesa.till_number'),
            'environment'      => $store->mpesa_environment               ?? config('mpesa.environment', 'sandbox'),
            'callback_base_url'=> $store->mpesa_callback_base_url         ?? config('mpesa.callback_base_url'),
            'callback_shared_secret' => config('mpesa.callback_shared_secret'),
        ];
    }

    private function clientForStore(Store $store): DarajaClient
    {
        return new DarajaClient($this->resolveCredentialsForStore($store));
    }

    private function stkCallbackUrl(array $creds): string
    {
        $base   = rtrim($creds['callback_base_url'] ?: config('app.url'), '/');
        $path   = config('mpesa.callback_paths.stk');
        $secret = $creds['callback_shared_secret'];

        return $base . $path . ($secret ? '?token=' . urlencode($secret) : '');
    }

    // ─────────────────────────────────────────────────────────────
    //  1️⃣  STK Push — initiate
    // ─────────────────────────────────────────────────────────────

    /**
     * Push an STK request to the customer's phone.
     * Idempotent per billing_id — safe to call twice.
     */
    public function initiateStkPushForBilling(
        Billing $billing,
        User    $user,
        string  $phone,
        ?float  $amount = null,
        array   $splitAllocations = [],
        int     $pointsRedeemed = 0
    ): MpesaTransaction {
        $store  = $billing->store()->firstOrFail();
        $amount = (float) ($amount ?? $billing->balance_due);

        if ($amount <= 0) {
            throw new RuntimeException('Nothing to charge — billing balance is zero.');
        }

        return $this->initiateStkPush(
            store:            $store,
            user:             $user,
            phone:            $phone,
            amount:           $amount,
            accountReference: substr($billing->invnumber ?? ('INV' . $billing->billing_id), 0, 12),
            transactionDesc:  'POS Sale',
            billingId:        $billing->billing_id,
            grnId:            null,
            lockKey:          "mpesa:lock:billing:{$billing->billing_id}",
            pendingContext:   [
                'split_allocations' => array_values($splitAllocations),
                'points_redeemed' => max($pointsRedeemed, 0),
            ],
        );
    }

    public function initiateStkPushForGrn(
        Grn     $grn,
        User    $user,
        string  $phone,
        ?float  $amount = null
    ): MpesaTransaction {
        $store  = $grn->store()->firstOrFail();
        $amount = (float) ($amount ?? $grn->balance_due);

        if ($amount <= 0) {
            throw new RuntimeException('Nothing to charge — GRN balance is zero.');
        }

        return $this->initiateStkPush(
            store:            $store,
            user:             $user,
            phone:            $phone,
            amount:           $amount,
            accountReference: substr($grn->grn_number ?? ('GRN' . $grn->grn_id), 0, 12),
            transactionDesc:  'Supplier Pay',
            billingId:        null,
            grnId:            $grn->grn_id,
            lockKey:          "mpesa:lock:grn:{$grn->grn_id}",
        );
    }

    private function initiateStkPush(
        Store   $store,
        User    $user,
        string  $phone,
        float   $amount,
        string  $accountReference,
        string  $transactionDesc,
        ?int    $billingId,
        ?int    $grnId,
        string  $lockKey,
        array   $pendingContext = [],
    ): MpesaTransaction {
        // ── Idempotency: if a pending attempt exists within TTL, return it.
        $existing = MpesaTransaction::query()
            ->where(function ($q) use ($billingId, $grnId) {
                if ($billingId) $q->where('billing_id', $billingId);
                if ($grnId)     $q->orWhere('grn_id', $grnId);
            })
            ->whereIn('status', ['pending', 'sent'])
            ->where('created_at', '>=', now()->subSeconds(config('mpesa.idempotency_ttl', 90)))
            ->latest('mpesa_transaction_id')
            ->first();

        if ($existing) {
            Log::info('[Mpesa] Reusing in-flight STK push', [
                'txn_id' => $existing->mpesa_transaction_id,
                'billing_id' => $billingId,
                'grn_id' => $grnId,
            ]);
            return $existing;
        }

        // Acquire a short-lived distributed lock so two concurrent HTTP
        // requests can't both create fresh attempts.
        $lock = Cache::lock($lockKey, 15);
        if (!$lock->get()) {
            throw new RuntimeException('Another payment is already being processed for this order.');
        }

        try {
            $creds  = $this->resolveCredentialsForStore($store);
            $client = new DarajaClient($creds);

            $idempotencyKey = 'idem-' . ($billingId ?? '') . '-' . ($grnId ?? '') . '-' . Str::random(8);

            $txn = MpesaTransaction::create([
                'store_id'          => $store->store_id,
                'billing_id'        => $billingId,
                'grn_id'            => $grnId,
                'user_id'           => $user->user_id,
                'channel'           => 'stk_push',
                'shortcode_type'    => $creds['shortcode_type'],
                'idempotency_key'   => $idempotencyKey,
                'amount'            => $amount,
                'phone_number'      => DarajaClient::normalisePhone($phone),
                'account_reference' => $accountReference,
                'transaction_desc'  => $transactionDesc,
                'status'            => 'pending',
                'environment'       => $creds['environment'],
                'request_payload'   => $pendingContext ?: null,
            ]);

            try {
                $response = $client->stkPush([
                    'amount'            => $amount,
                    'phone'             => $phone,
                    'account_reference' => $accountReference,
                    'transaction_desc'  => $transactionDesc,
                    'callback_url'      => $this->stkCallbackUrl($creds),
                ]);

                $txn->update([
                    'status'              => 'sent',
                    'merchant_request_id' => $response['MerchantRequestID'] ?? null,
                    'checkout_request_id' => $response['CheckoutRequestID'] ?? null,
                    'result_desc'         => $response['ResponseDescription'] ?? null,
                    'request_payload'     => [
                        ...($txn->request_payload ?? []),
                        'daraja_response' => $response,
                    ],
                ]);
            } catch (\Throwable $e) {
                $txn->update([
                    'status'      => 'failed',
                    'result_code' => 'STK_ERR',
                    'result_desc' => $e->getMessage(),
                ]);
                throw $e;
            }

            return $txn->fresh();
        } finally {
            optional($lock)->release();
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  2️⃣  STK Push — Safaricom callback
    // ─────────────────────────────────────────────────────────────

    /**
     * Handle STK Push callback body.
     *
     * Callback body shape (Safaricom):
     * {
     *   "Body": {
     *     "stkCallback": {
     *       "MerchantRequestID": "...", "CheckoutRequestID": "...",
     *       "ResultCode": 0, "ResultDesc": "The service request is processed successfully.",
     *       "CallbackMetadata": {
     *         "Item": [
     *           {"Name":"Amount","Value":100.00},
     *           {"Name":"MpesaReceiptNumber","Value":"QGH7X8Y2K1"},
     *           {"Name":"TransactionDate","Value":20260702101530},
     *           {"Name":"PhoneNumber","Value":254712345678}
     *         ]
     *       }
     *     }
     *   }
     * }
     */
    public function handleStkCallback(array $payload): MpesaTransaction
    {
        $callback = data_get($payload, 'Body.stkCallback', []);
        $checkoutId = data_get($callback, 'CheckoutRequestID');

        if (!$checkoutId) {
            throw new RuntimeException('Malformed STK callback: missing CheckoutRequestID');
        }

        $txn = MpesaTransaction::where('checkout_request_id', $checkoutId)->first();
        if (!$txn) {
            Log::warning('[Mpesa] STK callback for unknown checkout_request_id', ['id' => $checkoutId]);
            throw new RuntimeException('Unknown checkout_request_id');
        }

        // Already finalised? Ignore duplicate callbacks (Safaricom sometimes retries).
        if ($txn->isFinal()) {
            return $txn;
        }

        $resultCode = (int) data_get($callback, 'ResultCode', 1);
        $resultDesc = (string) data_get($callback, 'ResultDesc', 'Unknown');

        $meta = collect(data_get($callback, 'CallbackMetadata.Item', []))
            ->keyBy('Name')
            ->map(fn ($item) => $item['Value'] ?? null);

        $updates = [
            'result_code'      => (string) $resultCode,
            'result_desc'      => $resultDesc,
            'callback_payload' => $payload,
        ];

        if ($resultCode === 0) {
            // ✅ Success
            $updates['status']           = 'success';
            $updates['mpesa_receipt']    = (string) ($meta['MpesaReceiptNumber'] ?? null);
            $updates['phone_number']     = (string) ($meta['PhoneNumber'] ?? $txn->phone_number);
            $updates['transaction_date'] = $this->parseMpesaDate($meta['TransactionDate'] ?? null);

            // Verify amount matches — reject if mismatched (prevents replay).
            $callbackAmount = (float) ($meta['Amount'] ?? 0);
            if (abs($callbackAmount - (float) $txn->amount) > 0.01) {
                Log::warning('[Mpesa] Amount mismatch on STK callback', [
                    'txn_id' => $txn->mpesa_transaction_id,
                    'expected' => $txn->amount,
                    'got' => $callbackAmount,
                ]);
                $updates['status']      = 'failed';
                $updates['result_desc'] = 'Amount mismatch: expected ' . $txn->amount . ', got ' . $callbackAmount;
            }
        } elseif ($resultCode === 1032) {
            $updates['status'] = 'cancelled'; // customer pressed cancel
        } else {
            $updates['status'] = 'failed';
        }

        $txn->update($updates);
        $txn = $txn->fresh();

        // If success, materialise a Payment row via PaymentService.
        if ($txn->status === 'success') {
            $this->finaliseSuccessfulTransaction($txn);
        }

        return $txn;
    }

    // ─────────────────────────────────────────────────────────────
    //  3️⃣  C2B — customer initiated Paybill/Till (validation + confirmation)
    // ─────────────────────────────────────────────────────────────

    /**
     * Validation URL — Safaricom asks "is this account_ref valid?"
     * Return a Cancelled/Completed response.
     *
     * Payload shape:
     * {
     *   "TransactionType":"Pay Bill", "TransID":"QGH7...","TransTime":"20260702103045",
     *   "TransAmount":"1000.00", "BusinessShortCode":"600000",
     *   "BillRefNumber":"INV-000123", "InvoiceNumber":"",
     *   "OrgAccountBalance":"", "ThirdPartyTransID":"",
     *   "MSISDN":"254712345678", "FirstName":"John", "MiddleName":"", "LastName":"Doe"
     * }
     */
    public function handleC2bValidation(array $payload): array
    {
        $billRef = trim((string) data_get($payload, 'BillRefNumber', ''));

        // Try to find matching billing by invnumber.
        $billing = null;
        if ($billRef !== '') {
            $billing = Billing::where('invnumber', $billRef)
                ->orWhere('uuid', $billRef)
                ->first();
        }

        if ($billing && $billing->status !== 'paid') {
            return ['ResultCode' => 0, 'ResultDesc' => 'Accepted'];
        }

        // Unknown ref — still accept so the customer isn't charged twice at Safaricom's side.
        // Confirmation stage will log it as unmatched for reconciliation.
        return ['ResultCode' => 0, 'ResultDesc' => 'Accepted'];
    }

    /**
     * Confirmation URL — money already deducted from customer.
     * We MUST return 200 quickly regardless of matching logic.
     */
    public function handleC2bConfirmation(array $payload): MpesaTransaction
    {
        $mpesaReceipt = (string) data_get($payload, 'TransID');
        $amount       = (float) data_get($payload, 'TransAmount', 0);
        $phone        = (string) data_get($payload, 'MSISDN', '');
        $billRef      = trim((string) data_get($payload, 'BillRefNumber', ''));

        // Dedupe on receipt number.
        $existing = MpesaTransaction::where('mpesa_receipt', $mpesaReceipt)->first();
        if ($existing) {
            return $existing;
        }

        $billing = null;
        if ($billRef !== '') {
            $billing = Billing::where('invnumber', $billRef)
                ->orWhere('uuid', $billRef)
                ->first();
        }

        $store = $billing?->store ?? Store::query()
            ->where('mpesa_shortcode', data_get($payload, 'BusinessShortCode'))
            ->first();

        $txn = MpesaTransaction::create([
            'store_id'          => $store?->store_id,
            'billing_id'        => $billing?->billing_id,
            'user_id'           => null,
            'channel'           => 'c2b',
            'shortcode_type'    => str_contains(strtolower((string) data_get($payload, 'TransactionType')), 'buy')
                                    ? 'till' : 'paybill',
            'mpesa_receipt'     => $mpesaReceipt,
            'amount'            => $amount,
            'phone_number'      => $phone,
            'account_reference' => $billRef,
            'transaction_desc'  => data_get($payload, 'TransactionType', 'C2B'),
            'transaction_date'  => $this->parseMpesaDate(data_get($payload, 'TransTime')),
            'status'            => 'success',
            'result_code'       => '0',
            'result_desc'       => 'C2B confirmation received',
            'callback_payload'  => $payload,
            'environment'       => config('mpesa.environment', 'sandbox'),
        ]);

        if ($billing) {
            $this->finaliseSuccessfulTransaction($txn);
        } else {
            Log::warning('[Mpesa] C2B confirmation without matching billing', [
                'receipt' => $mpesaReceipt,
                'ref' => $billRef,
            ]);
        }

        return $txn;
    }

    // ─────────────────────────────────────────────────────────────
    //  4️⃣  Manual receipt entry (cashier types the M-Pesa code)
    // ─────────────────────────────────────────────────────────────

    /**
     * Cashier typed an M-Pesa code manually. We:
     *   1. Reject if that receipt already exists (prevents double-usage).
     *   2. Look for a matching C2B row in mpesa_transactions (already received via callback).
     *   3. Optionally call Transaction Status API for live validation (requires initiator).
     */
    public function validateManualReceipt(
        Billing $billing,
        User    $user,
        string  $mpesaReceipt,
        float   $amount,
        ?string $phone = null
    ): MpesaTransaction {
        $mpesaReceipt = strtoupper(trim($mpesaReceipt));

        if (!preg_match('/^[A-Z0-9]{8,12}$/', $mpesaReceipt)) {
            throw new RuntimeException('Invalid M-Pesa receipt format.');
        }

        // Already used against ANY payment/billing?
        $alreadyUsed = MpesaTransaction::where('mpesa_receipt', $mpesaReceipt)
            ->whereNotNull('payment_id')
            ->exists();

        if ($alreadyUsed) {
            throw new RuntimeException('This M-Pesa code has already been used on another sale.');
        }

        // Fast path: matching C2B row was already received.
        $existing = MpesaTransaction::where('mpesa_receipt', $mpesaReceipt)->first();

        if ($existing) {
            if (abs((float) $existing->amount - $amount) > 0.01) {
                throw new RuntimeException(sprintf(
                    'Receipt amount (%s) does not match sale amount (%s).',
                    number_format((float) $existing->amount, 2),
                    number_format($amount, 2)
                ));
            }

            // Attach to this billing.
            $existing->update([
                'billing_id' => $billing->billing_id,
                'user_id'    => $user->user_id,
                'store_id'   => $billing->store_id,
            ]);

            $this->finaliseSuccessfulTransaction($existing);
            return $existing->fresh();
        }

        // Slow path: hasn't arrived via C2B yet. Create a pending manual entry
        // and let the C2B confirmation callback (or admin retry) resolve it.
        $txn = MpesaTransaction::create([
            'store_id'          => $billing->store_id,
            'billing_id'        => $billing->billing_id,
            'user_id'           => $user->user_id,
            'channel'           => 'manual',
            'mpesa_receipt'     => $mpesaReceipt,
            'amount'            => $amount,
            'phone_number'      => $phone,
            'account_reference' => $billing->invnumber,
            'status'            => 'pending',
            'result_desc'       => 'Awaiting C2B confirmation for manual receipt entry',
            'environment'       => config('mpesa.environment', 'sandbox'),
        ]);

        throw new RuntimeException(
            'This M-Pesa code has not been received yet. Ask the customer to wait 30 seconds and try again, or verify the code with them.'
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  5️⃣  Poll status (frontend live-status modal)
    // ─────────────────────────────────────────────────────────────

    public function status(string $checkoutRequestId): array
    {
        $txn = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)->first();

        if (!$txn) {
            return ['status' => 'unknown'];
        }

        // If still pending and > 60s old, actively query Daraja (defence against lost callbacks).
        if (in_array($txn->status, ['pending', 'sent'], true)
            && $txn->created_at?->diffInSeconds(now()) > 60) {
            try {
                $store  = $txn->store()->first();
                $client = $this->clientForStore($store);
                $query  = $client->stkQuery($checkoutRequestId);

                $resultCode = (int) ($query['ResultCode'] ?? 1);
                if ($resultCode === 0) {
                    // The customer paid but callback never came — treat as success.
                    $txn->update([
                        'status'           => 'success',
                        'result_code'      => (string) $resultCode,
                        'result_desc'      => $query['ResultDesc'] ?? 'Confirmed via query',
                        'callback_payload' => $query,
                    ]);
                    $this->finaliseSuccessfulTransaction($txn->fresh());
                } elseif ($resultCode === 1032) {
                    $txn->update(['status' => 'cancelled', 'result_code' => '1032', 'result_desc' => 'Cancelled by user']);
                } elseif ($resultCode !== 1037 /* still waiting */) {
                    $txn->update(['status' => 'failed', 'result_code' => (string) $resultCode, 'result_desc' => $query['ResultDesc'] ?? 'Failed']);
                }

                $txn = $txn->fresh();
            } catch (\Throwable $e) {
                Log::warning('[Mpesa] stkQuery polling failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'status'            => $txn->status,
            'mpesa_receipt'     => $txn->mpesa_receipt,
            'result_desc'       => $txn->result_desc,
            'amount'            => (float) $txn->amount,
            'phone_number'      => $txn->phone_number,
            'transaction_date'  => $txn->transaction_date?->toIso8601String(),
            'payment_id'        => $txn->payment_id,
            'mpesa_transaction' => $txn,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    //  Internal: bridge successful M-Pesa txn → payments table
    // ─────────────────────────────────────────────────────────────

    private function finaliseSuccessfulTransaction(MpesaTransaction $txn): void
    {
        if ($txn->payment_id) return; // already reconciled

        DB::transaction(function () use ($txn) {
            $txn = MpesaTransaction::query()->lockForUpdate()->find($txn->mpesa_transaction_id);
            if ($txn->payment_id) return;

            if ($txn->billing_id) {
                $billing = Billing::query()->lockForUpdate()->find($txn->billing_id);
                if (!$billing || $billing->status === 'paid') {
                    Log::info('[Mpesa] Billing already paid, skipping', ['billing_id' => $txn->billing_id]);
                    return;
                }

                $user = $txn->user()->first() ?? $billing->user()->first();
                if (!$user) {
                    Log::warning('[Mpesa] Cannot finalise: no user context', ['txn_id' => $txn->mpesa_transaction_id]);
                    return;
                }

                $pendingSplitAllocations = data_get($txn->request_payload, 'split_allocations', []);
                $pointsRedeemed = (int) data_get($txn->request_payload, 'points_redeemed', 0);

                $paymentPayload = !empty($pendingSplitAllocations)
                    ? [
                        'payment_allocations' => $this->materializeSplitAllocationsFromSuccessfulStk($txn, $pendingSplitAllocations),
                        'points_redeemed' => $pointsRedeemed,
                      ]
                    : [
                        'payment_method'  => 'mpesa',
                        'amount_received' => (float) $txn->amount,
                        'amount_tendered' => (float) $txn->amount,
                        'mpesa_phone'     => $txn->phone_number,
                        'mpesa_code'      => $txn->mpesa_receipt,
                      ];

                $payment = $this->paymentService->charge($billing, $user, $paymentPayload);

                $resolvedPaymentId = Payment::query()
                    ->where('billing_id', $billing->billing_id)
                    ->where('payment_method', 'mpesa')
                    ->where('amount_received', (float) $txn->amount)
                    ->latest('payment_id')
                    ->value('payment_id') ?? $payment->payment_id;

                Payment::where('payment_id', $resolvedPaymentId)->update([
                    'mpesa_receipt'         => $txn->mpesa_receipt,
                    'mpesa_phone'           => $txn->phone_number,
                    'mpesa_transaction_id'  => $txn->mpesa_transaction_id,
                ]);

                $txn->update(['payment_id' => $resolvedPaymentId]);
            }

            if ($txn->grn_id) {
                $payment = $this->grnService->recordMpesaSettlement($txn);

                if ($payment) {
                    $txn->update(['payment_id' => $payment->grn_payment_id]);
                }
            }


            $this->auditLogService->log(
                'mpesa.confirmed',
                $txn,
                null,
                $txn->toArray(),
                ['receipt' => $txn->mpesa_receipt],
                $txn->store_id
            );
        });
    }

    private function materializeSplitAllocationsFromSuccessfulStk(MpesaTransaction $txn, array $allocations): array
    {
        return array_map(function (array $row) use ($txn) {
            $method = strtolower((string) ($row['payment_method'] ?? ''));
            $mode = strtolower((string) ($row['mpesa_mode'] ?? ''));

            if ($method === 'mpesa' && $mode === 'stk') {
                return [
                    ...$row,
                    'payment_method' => 'mpesa',
                    'amount_received' => (float) ($row['amount_received'] ?? $txn->amount),
                    'amount_tendered' => (float) ($row['amount_tendered'] ?? $row['amount_received'] ?? $txn->amount),
                    'mpesa_phone' => $txn->phone_number,
                    'mpesa_code' => $txn->mpesa_receipt,
                    'mpesa_mode' => 'manual',
                ];
            }

            return $row;
        }, $allocations);
    }

    /**
     * Daraja timestamps come as YmdHis integers (20260702101530).
     */
    private function parseMpesaDate($value): ?\DateTime
    {
        if (!$value) return null;
        try {
            return \DateTime::createFromFormat('YmdHis', (string) $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
