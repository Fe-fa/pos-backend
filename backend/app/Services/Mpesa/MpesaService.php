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
            'transaction_status_initiator' => config('mpesa.transaction_status.initiator_name'),
            'transaction_status_security_credential' => config('mpesa.transaction_status.security_credential'),
            'pull_enabled' => (bool) config('mpesa.pull.enabled', true),
            'pull_auto_register' => (bool) config('mpesa.pull.auto_register', true),
            'pull_nominated_number' => config('mpesa.pull.nominated_number'),
            'pull_lookback_minutes' => max(1, (int) config('mpesa.pull.lookback_minutes', 60)),
            'pull_max_offsets' => max(1, (int) config('mpesa.pull.max_offsets', 3)),
            'pull_registration_cache_ttl' => max(300, (int) config('mpesa.pull.registration_cache_ttl', 86400)),
        ];
    }

    private function clientForStore(Store $store): DarajaClient
    {
        return new DarajaClient($this->resolveCredentialsForStore($store));
    }

    private function callbackUrl(array $creds, string $pathKey): string
    {
        $base = rtrim($creds['callback_base_url'] ?: config('app.url'), '/');
        $path = (string) config("mpesa.callback_paths.{$pathKey}", '');
        $secret = $creds['callback_shared_secret'];

        if ($path === '') {
            throw new RuntimeException("Missing configured M-Pesa callback path [{$pathKey}].");
        }

        return $base . $path . ($secret ? '?token=' . urlencode($secret) : '');
    }

    private function stkCallbackUrl(array $creds): string
    {
        return $this->callbackUrl($creds, 'stk');
    }

    private function txStatusResultUrl(array $creds): string
    {
        return $this->callbackUrl($creds, 'tx_status_result');
    }

    private function txStatusTimeoutUrl(array $creds): string
    {
        return $this->callbackUrl($creds, 'tx_status_timeout');
    }

    private function pullCallbackUrl(array $creds): string
    {
        return $this->callbackUrl($creds, 'c2b_confirmation');
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

        // Dedupe on receipt number, but still allow pending manual rows to be completed.
        $existing = MpesaTransaction::where('mpesa_receipt', $mpesaReceipt)->latest('mpesa_transaction_id')->first();
        if ($existing) {
            if ($existing->isSuccessful()) {
                return $existing;
            }

            $existing->update([
                'billing_id' => $existing->billing_id,
                'store_id' => $existing->store_id,
                'channel' => $existing->channel ?: 'c2b',
                'shortcode_type' => str_contains(strtolower((string) data_get($payload, 'TransactionType')), 'buy')
                    ? 'till' : 'paybill',
                'amount' => $amount,
                'phone_number' => $phone ?: $existing->phone_number,
                'account_reference' => $billRef !== '' ? $billRef : $existing->account_reference,
                'transaction_desc' => data_get($payload, 'TransactionType', $existing->transaction_desc ?: 'C2B'),
                'transaction_date' => $this->parseMpesaDate(data_get($payload, 'TransTime')),
                'status' => 'success',
                'result_code' => '0',
                'result_desc' => 'C2B confirmation received',
                'callback_payload' => $payload,
                'environment' => $existing->environment ?: config('mpesa.environment', 'sandbox'),
            ]);

            if ($existing->billing_id) {
                $this->finaliseSuccessfulTransaction($existing->fresh());
            }

            return $existing->fresh();
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
     * Cashier typed an M-Pesa code manually. We first try to reconcile with an
     * already-known C2B transaction. If not available yet, we dispatch a live
     * Transaction Status request and let the callback complete the payment.
     */
    public function validateManualReceipt(
        Billing $billing,
        User    $user,
        string  $mpesaReceipt,
        float   $amount,
        ?string $phone = null,
        array   $splitAllocations = [],
        int     $pointsRedeemed = 0,
    ): array {
        $mpesaReceipt = strtoupper(trim($mpesaReceipt));
        $normalizedPhone = $phone ? DarajaClient::normalisePhone($phone) : null;
        $amount = round((float) $amount, 2);

        if (!preg_match('/^[A-Z0-9]{8,12}$/', $mpesaReceipt)) {
            throw new RuntimeException('Invalid M-Pesa receipt format.');
        }

        $alreadyUsed = MpesaTransaction::query()
            ->where('mpesa_receipt', $mpesaReceipt)
            ->whereNotNull('payment_id')
            ->where(function ($query) use ($billing) {
                $query->whereNull('billing_id')
                    ->orWhere('billing_id', '!=', $billing->billing_id);
            })
            ->exists();

        if ($alreadyUsed) {
            throw new RuntimeException('This M-Pesa code has already been used on another sale.');
        }

        $store = $billing->store()->firstOrFail();
        $creds = $this->resolveCredentialsForStore($store);

        $existing = MpesaTransaction::query()
            ->where('mpesa_receipt', $mpesaReceipt)
            ->latest('mpesa_transaction_id')
            ->first();

        if ($existing) {
            $existing = $this->attachManualReceiptContext(
                $existing,
                $billing,
                $user,
                $store,
                $amount,
                $normalizedPhone,
                $splitAllocations,
                $pointsRedeemed,
            );

            if ($existing->amount && abs((float) $existing->amount - $amount) > 0.01) {
                throw new RuntimeException(sprintf(
                    'Receipt amount (%s) does not match sale amount (%s).',
                    number_format((float) $existing->amount, 2),
                    number_format($amount, 2)
                ));
            }

            if ($existing->status === 'success') {
                $this->finaliseSuccessfulTransaction($existing->fresh());
                return $this->formatTransactionStatusPayload($existing->fresh());
            }

            if (in_array($existing->status, ['pending', 'sent'], true)
                && ($existing->originator_conversation_id || $existing->conversation_id)) {
                return $this->formatTransactionStatusPayload($existing->fresh());
            }
        }

        $initiator = trim((string) ($creds['transaction_status_initiator'] ?? ''));
        $securityCredential = trim((string) ($creds['transaction_status_security_credential'] ?? ''));

        if ($initiator === '' || $securityCredential === '') {
            throw new RuntimeException(
                'Transaction Status is not configured for this store. Add the Transaction Status initiator and security credential, or use phone lookup / wait for the C2B callback.'
            );
        }

        $txn = $existing ?: new MpesaTransaction();
        $txn->fill([
            'store_id' => $store->store_id,
            'billing_id' => $billing->billing_id,
            'user_id' => $user->user_id,
            'channel' => 'manual_tx_status',
            'mpesa_receipt' => $mpesaReceipt,
            'amount' => $txn->amount ?: $amount,
            'phone_number' => $normalizedPhone ?: $txn->phone_number,
            'account_reference' => $billing->invnumber,
            'status' => 'pending',
            'result_code' => null,
            'result_desc' => 'Sent for Transaction Status validation',
            'environment' => $creds['environment'],
            'request_payload' => [
                ...($txn->request_payload ?? []),
                'split_allocations' => array_values($splitAllocations),
                'points_redeemed' => max(0, $pointsRedeemed),
                'manual_validation_phone' => $normalizedPhone,
                'manual_validation_amount' => $amount,
            ],
        ]);
        $txn->save();

        $response = $this->clientForStore($store)->transactionStatus(
            $mpesaReceipt,
            $initiator,
            $securityCredential,
            $this->txStatusResultUrl($creds),
            $this->txStatusTimeoutUrl($creds),
            remarks: 'POS manual validation',
            occasion: (string) ($billing->invnumber ?: 'Billing-' . $billing->billing_id),
        );

        $txn->update([
            'status' => (($response['ResponseCode'] ?? '1') === '0') ? 'sent' : 'failed',
            'originator_conversation_id' => $response['OriginatorConversationID'] ?? $txn->originator_conversation_id,
            'conversation_id' => $response['ConversationID'] ?? $txn->conversation_id,
            'result_code' => (string) ($response['ResponseCode'] ?? '1'),
            'result_desc' => $response['ResponseDescription'] ?? $txn->result_desc,
            'callback_payload' => [
                'transaction_status_request_ack' => $response,
            ],
        ]);

        if (($response['ResponseCode'] ?? '1') !== '0') {
            throw new RuntimeException($response['ResponseDescription'] ?? 'M-Pesa Transaction Status request was rejected.');
        }

        return $this->formatTransactionStatusPayload($txn->fresh());
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

        return $this->formatTransactionStatusPayload($txn);
    }

    public function manualStatus(string $trackingReference): array
    {
        $txn = $this->findTransactionByTrackingReference($trackingReference);

        if (!$txn) {
            return ['status' => 'unknown'];
        }

        return $this->formatTransactionStatusPayload($txn);
    }

    public function pullMatchForBilling(
        Billing $billing,
        User $user,
        string $phone,
        float $amount,
        array $splitAllocations = [],
        int $pointsRedeemed = 0,
        ?int $lookbackMinutes = null,
    ): array {
        $store = $billing->store()->firstOrFail();
        $creds = $this->resolveCredentialsForStore($store);

        if (!($creds['pull_enabled'] ?? true)) {
            throw new RuntimeException('Pull Transactions API is disabled for this store.');
        }

        $normalizedPhone = DarajaClient::normalisePhone($phone);
        $client = $this->clientForStore($store);
        $this->ensurePullRegistration($client, $creds);

        $lookbackMinutes = max(1, min((int) ($lookbackMinutes ?? $creds['pull_lookback_minutes'] ?? 60), 48 * 60));
        $end = now();
        $start = (clone $end)->subMinutes($lookbackMinutes);

        $localMatch = $this->findLocalRecentPhoneAmountMatch($billing, $normalizedPhone, $amount, $lookbackMinutes);
        if ($localMatch) {
            $localMatch->fill([
                'store_id' => $billing->store_id,
                'billing_id' => $billing->billing_id,
                'user_id' => $user->user_id,
                'phone_number' => $normalizedPhone ?: $localMatch->phone_number,
                'account_reference' => $billing->invnumber ?: $localMatch->account_reference,
                'environment' => $creds['environment'],
                'request_payload' => [
                    ...($localMatch->request_payload ?? []),
                    'split_allocations' => array_values($splitAllocations),
                    'points_redeemed' => max(0, $pointsRedeemed),
                    'pull_lookup_phone' => $normalizedPhone,
                    'pull_lookup_amount' => $amount,
                    'matched_locally' => true,
                ],
            ]);

            if (! in_array($localMatch->status, ['success'], true)) {
                $localMatch->status = 'success';
                $localMatch->result_code = $localMatch->result_code ?: '0';
                $localMatch->result_desc = 'Matched from locally received C2B confirmation';
            }

            $localMatch->save();
            $this->finaliseSuccessfulTransaction($localMatch->fresh());

            return $this->formatTransactionStatusPayload($localMatch->fresh());
        }

        $allTransactions = [];
        $offsetStep = 100;
        $maxOffsets = max(1, (int) ($creds['pull_max_offsets'] ?? 3));

        for ($page = 0; $page < $maxOffsets; $page++) {
            $offset = $page * $offsetStep;
            $response = $client->pullTransactions(
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $offset,
            );

            $batch = $this->flattenPulledTransactions($response);
            if (empty($batch)) {
                break;
            }

            $allTransactions = array_merge($allTransactions, $batch);
            if (count($batch) < $offsetStep) {
                break;
            }
        }

        $match = $this->findBestPulledTransactionMatch($allTransactions, $billing, $normalizedPhone, $amount);
        if (!$match) {
            return [
                'status' => 'not_found',
                'amount' => $amount,
                'phone_number' => $normalizedPhone,
                'lookback_minutes' => $lookbackMinutes,
                'matches_checked' => count($allTransactions),
            ];
        }

        $receipt = strtoupper((string) ($match['transactionId']
            ?? $match['TransID']
            ?? $match['TransactionID']
            ?? $match['transactionCode']
            ?? $match['TransactionCode']
            ?? $match['receipt']
            ?? ''));
        if ($receipt === '') {
            throw new RuntimeException('Pull Transactions returned a match but no transaction code.');
        }

        $alreadyUsed = MpesaTransaction::query()
            ->where('mpesa_receipt', $receipt)
            ->whereNotNull('payment_id')
            ->where(function ($query) use ($billing) {
                $query->whereNull('billing_id')
                    ->orWhere('billing_id', '!=', $billing->billing_id);
            })
            ->exists();

        if ($alreadyUsed) {
            throw new RuntimeException('The matched M-Pesa code has already been used on another sale.');
        }

        $txn = MpesaTransaction::query()->where('mpesa_receipt', $receipt)->latest('mpesa_transaction_id')->first()
            ?: new MpesaTransaction();

        $txn->fill([
            'store_id' => $billing->store_id,
            'billing_id' => $billing->billing_id,
            'user_id' => $user->user_id,
            'channel' => 'pull_api',
            'shortcode_type' => $creds['shortcode_type'] ?? null,
            'mpesa_receipt' => $receipt,
            'amount' => (float) ($match['amount'] ?? $match['TransAmount'] ?? $match['transactionAmount'] ?? $amount),
            'phone_number' => $normalizedPhone,
            'account_reference' => (string) ($match['billreference'] ?? $match['BillRefNumber'] ?? $match['accountReference'] ?? $match['AccountReference'] ?? $billing->invnumber),
            'transaction_desc' => (string) ($match['transactiontype'] ?? $match['TransactionType'] ?? $match['transactionType'] ?? 'Pull Transactions match'),
            'transaction_date' => $this->parseFlexibleMpesaDate($match['trxDate'] ?? $match['TransTime'] ?? $match['transactionDate'] ?? $match['TransactionDate'] ?? null),
            'status' => 'success',
            'result_code' => '0',
            'result_desc' => 'Matched via Pull Transactions API',
            'environment' => $creds['environment'],
            'request_payload' => [
                ...($txn->request_payload ?? []),
                'split_allocations' => array_values($splitAllocations),
                'points_redeemed' => max(0, $pointsRedeemed),
                'pull_lookup_phone' => $normalizedPhone,
                'pull_lookup_amount' => $amount,
            ],
            'callback_payload' => [
                'pull_match' => $match,
            ],
        ]);
        $txn->save();

        $this->finaliseSuccessfulTransaction($txn->fresh());

        return $this->formatTransactionStatusPayload($txn->fresh());
    }

    public function handleTransactionStatusResult(array $payload): MpesaTransaction
    {
        $params = $this->mapResultParameters(data_get($payload, 'Result.ResultParameters.ResultParameter', []));
        $txn = $this->findTransactionByResultPayload($payload, $params);

        if (!$txn) {
            throw new RuntimeException('No pending manual validation transaction matched the Transaction Status callback.');
        }

        $resultCode = (int) data_get($payload, 'Result.ResultCode', 1);
        $resultDesc = (string) data_get($payload, 'Result.ResultDesc', 'Unknown');
        $receipt = (string) ($this->firstResultParameterValue($params, 'ReceiptNo')
            ?? data_get($payload, 'Result.TransactionID')
            ?? $txn->mpesa_receipt);
        $callbackAmount = (float) ($this->firstResultParameterValue($params, 'Amount') ?? $txn->amount ?? 0);
        $transactionStatus = strtolower((string) ($this->firstResultParameterValue($params, 'TransactionStatus') ?? ''));

$updates = [
    'originator_conversation_id' => data_get($payload, 'Result.OriginatorConversationID') ?? $txn->originator_conversation_id,
    'conversation_id' => data_get($payload, 'Result.ConversationID') ?? $txn->conversation_id,
    'callback_payload' => $payload,
    'result_code' => (string) $resultCode,
    'result_desc' => $resultDesc,
    'transaction_date' => $this->parseFlexibleMpesaDate(
        $this->firstResultParameterValue($params, 'FinalisedTime')
        ?? $this->firstResultParameterValue($params, 'InitiatedTime')
    ),
    'phone_number' => $this->extractPhoneFromResultParameters($params) ?: $txn->phone_number,
];

if ($resultCode === 0 && ($transactionStatus === '' || in_array($transactionStatus, ['completed', 'success', 'successful'], true))) {
    if ($txn->amount && abs((float) $txn->amount - $callbackAmount) > 0.01) {
        $updates['status'] = 'failed';
        $updates['result_desc'] = 'Amount mismatch: expected ' . $txn->amount . ', got ' . $callbackAmount;
    } else {
        $updates['status'] = 'success';
        $updates['amount'] = $callbackAmount ?: $txn->amount;
        $updates['mpesa_receipt'] = $receipt;
    }
} else {
    $updates['status'] = 'failed';
}

        $txn->update($updates);
        $txn = $txn->fresh();

        if ($txn->status === 'success') {
            $this->finaliseSuccessfulTransaction($txn);
        }

        return $txn;
    }

    public function handleTransactionStatusTimeout(array $payload): ?MpesaTransaction
    {
        $txn = $this->findTransactionByTrackingReference(
            (string) (data_get($payload, 'Result.OriginatorConversationID')
                ?? data_get($payload, 'OriginatorConversationID')
                ?? data_get($payload, 'ConversationID')
                ?? '')
        );

        if (!$txn) {
            Log::warning('[Mpesa] Transaction Status timeout for unknown transaction', $payload);
            return null;
        }

        $txn->update([
            'status' => 'timeout',
            'result_desc' => 'Transaction Status request timed out',
            'callback_payload' => $payload,
        ]);

        return $txn->fresh();
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

            if ($method === 'mpesa' && in_array($mode, ['stk', 'manual'], true)) {
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

    private function attachManualReceiptContext(
        MpesaTransaction $txn,
        Billing $billing,
        User $user,
        Store $store,
        float $amount,
        ?string $phone,
        array $splitAllocations,
        int $pointsRedeemed,
    ): MpesaTransaction {
        $txn->fill([
            'store_id' => $billing->store_id ?: $store->store_id,
            'billing_id' => $billing->billing_id,
            'user_id' => $user->user_id,
            'channel' => $txn->channel ?: 'manual',
            'amount' => $txn->amount ?: $amount,
            'phone_number' => $phone ?: $txn->phone_number,
            'account_reference' => $billing->invnumber,
            'request_payload' => [
                ...($txn->request_payload ?? []),
                'split_allocations' => array_values($splitAllocations),
                'points_redeemed' => max(0, $pointsRedeemed),
                'manual_validation_phone' => $phone,
                'manual_validation_amount' => $amount,
            ],
        ]);
        $txn->save();

        return $txn->fresh();
    }

    private function ensurePullRegistration(DarajaClient $client, array $creds): void
    {
        if (!($creds['pull_auto_register'] ?? true)) {
            return;
        }

        $nominatedNumber = trim((string) ($creds['pull_nominated_number'] ?? ''));
        if ($nominatedNumber === '') {
            return;
        }

        $cacheKey = 'mpesa:pull:registered:' . md5(($creds['environment'] ?? '') . '|' . ($creds['shortcode'] ?? ''));
        if (Cache::get($cacheKey)) {
            return;
        }

        try {
            $response = $client->registerPullTransactions($nominatedNumber, $this->pullCallbackUrl($creds));
            $status = (string) ($response['ResponseStatus'] ?? $response['ResponseCode'] ?? '');
            if (in_array($status, ['1000', '1001'], true)) {
                Cache::put($cacheKey, true, (int) ($creds['pull_registration_cache_ttl'] ?? 86400));
            }
        } catch (\Throwable $e) {
            Log::warning('[Mpesa] Pull registration failed', ['error' => $e->getMessage()]);
        }
    }

    private function flattenPulledTransactions(array $response): array
    {
        $flat = [];
        $seen = [];

        $walker = function ($value) use (&$flat, &$seen, &$walker) {
            if (!is_array($value)) {
                return;
            }

            $isTxnRow = array_key_exists('transactionId', $value)
                || array_key_exists('TransID', $value)
                || array_key_exists('TransactionID', $value)
                || array_key_exists('transactionCode', $value)
                || array_key_exists('TransactionCode', $value);

            if ($isTxnRow) {
                $signature = md5(json_encode($value));
                if (! isset($seen[$signature])) {
                    $seen[$signature] = true;
                    $flat[] = $value;
                }
                return;
            }

            foreach ($value as $nested) {
                $walker($nested);
            }
        };

        foreach ([$response, data_get($response, 'Response', []), data_get($response, 'ResponseData', []), data_get($response, 'Transactions', []), data_get($response, 'data', [])] as $root) {
            $walker($root);
        }

        return $flat;
    }

    private function findBestPulledTransactionMatch(array $transactions, Billing $billing, string $normalizedPhone, float $amount): ?array
    {
        $targetLast9 = substr($normalizedPhone, -9);
        $targetInvoice = strtolower(trim((string) ($billing->invnumber ?? '')));

        $matches = array_values(array_filter($transactions, function (array $txn) use ($targetLast9, $amount) {
            $rowPhone = $this->normalizeLoosePhoneForMatching(
                $txn['msisdn']
                ?? $txn['MSISDN']
                ?? $txn['phoneNumber']
                ?? $txn['PhoneNumber']
                ?? $txn['senderMsisdn']
                ?? null
            );
            if (!$rowPhone || substr($rowPhone, -9) !== $targetLast9) {
                return false;
            }

            $rowAmount = round((float) ($txn['amount'] ?? $txn['TransAmount'] ?? $txn['transactionAmount'] ?? 0), 2);
            return abs($rowAmount - round($amount, 2)) <= 0.01;
        }));

        if (empty($matches)) {
            return null;
        }

        usort($matches, function (array $left, array $right) use ($targetInvoice) {
            $leftRef = strtolower(trim((string) ($left['billreference'] ?? $left['BillRefNumber'] ?? $left['accountReference'] ?? $left['AccountReference'] ?? '')));
            $rightRef = strtolower(trim((string) ($right['billreference'] ?? $right['BillRefNumber'] ?? $right['accountReference'] ?? $right['AccountReference'] ?? '')));

            $leftScore = ($targetInvoice !== '' && $leftRef === $targetInvoice ? 10 : 0)
                + (($left['trxDate'] ?? $left['TransTime'] ?? $left['transactionDate'] ?? $left['TransactionDate'] ?? null) ? 1 : 0);
            $rightScore = ($targetInvoice !== '' && $rightRef === $targetInvoice ? 10 : 0)
                + (($right['trxDate'] ?? $right['TransTime'] ?? $right['transactionDate'] ?? $right['TransactionDate'] ?? null) ? 1 : 0);

            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            return strcmp((string) ($right['trxDate'] ?? $right['TransTime'] ?? $right['transactionDate'] ?? $right['TransactionDate'] ?? ''), (string) ($left['trxDate'] ?? $left['TransTime'] ?? $left['transactionDate'] ?? $left['TransactionDate'] ?? ''));
        });

        return $matches[0] ?? null;
    }

    private function findLocalRecentPhoneAmountMatch(Billing $billing, string $normalizedPhone, float $amount, int $lookbackMinutes): ?MpesaTransaction
    {
        $targetLast9 = substr($normalizedPhone, -9);

        return MpesaTransaction::query()
            ->where('store_id', $billing->store_id)
            ->where(function ($query) use ($billing) {
                $query->whereNull('payment_id')
                    ->orWhere('billing_id', $billing->billing_id);
            })
            ->whereBetween('created_at', [now()->subMinutes($lookbackMinutes), now()->addMinute()])
            ->whereIn('status', ['received', 'success', 'unassigned', 'conflict'])
            ->whereRaw('ABS(amount - ?) <= 0.01', [round($amount, 2)])
            ->get()
            ->filter(function (MpesaTransaction $txn) use ($targetLast9) {
                $rowPhone = $this->normalizeLoosePhoneForMatching($txn->phone_number);
                return $rowPhone && substr($rowPhone, -9) === $targetLast9;
            })
            ->sortByDesc(function (MpesaTransaction $txn) use ($billing) {
                $score = 0;
                if ($billing->invnumber && strcasecmp((string) $txn->account_reference, (string) $billing->invnumber) === 0) {
                    $score += 10;
                }

                return ($score * 1000000) + ((int) optional($txn->created_at)->timestamp);
            })
            ->first();
    }

    private function normalizeLoosePhoneForMatching($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return DarajaClient::normalisePhone((string) $value);
        } catch (\Throwable) {
            $digits = preg_replace('/\D+/', '', (string) $value);
            if ($digits === '') {
                return null;
            }
            if (str_starts_with($digits, '254') && strlen($digits) >= 12) {
                return substr($digits, 0, 12);
            }
            if (strlen($digits) >= 9) {
                return '254' . substr($digits, -9);
            }
            return null;
        }
    }

    private function findTransactionByTrackingReference(string $trackingReference): ?MpesaTransaction
    {
        $trackingReference = trim($trackingReference);
        if ($trackingReference === '') {
            return null;
        }

        return MpesaTransaction::query()
            ->where('originator_conversation_id', $trackingReference)
            ->orWhere('conversation_id', $trackingReference)
            ->orWhere('checkout_request_id', $trackingReference)
            ->orWhere('mpesa_receipt', strtoupper($trackingReference))
            ->latest('mpesa_transaction_id')
            ->first();
    }

    private function formatTransactionStatusPayload(MpesaTransaction $txn): array
    {
        return [
            'status' => $txn->status,
            'mpesa_transaction_id' => $txn->mpesa_transaction_id,
            'tracking_reference' => $txn->originator_conversation_id ?: $txn->conversation_id ?: $txn->checkout_request_id ?: $txn->mpesa_receipt,
            'originator_conversation_id' => $txn->originator_conversation_id,
            'conversation_id' => $txn->conversation_id,
            'checkout_request_id' => $txn->checkout_request_id,
            'mpesa_receipt' => $txn->mpesa_receipt,
            'result_desc' => $txn->result_desc,
            'amount' => (float) $txn->amount,
            'phone_number' => $txn->phone_number,
            'transaction_date' => $txn->transaction_date?->format(DATE_ATOM),
            'payment_id' => $txn->payment_id,
            'polling' => [
                'interval_ms' => (int) config('mpesa.polling.interval_ms'),
                'timeout_ms' => (int) config('mpesa.polling.timeout_ms'),
            ],
            'mpesa_transaction' => $txn,
        ];
    }

    private function mapResultParameters(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            $key = (string) ($item['Key'] ?? '');
            if ($key === '') {
                continue;
            }
            $value = $item['Value'] ?? null;
            if (array_key_exists($key, $mapped)) {
                if (!is_array($mapped[$key])) {
                    $mapped[$key] = [$mapped[$key]];
                }
                $mapped[$key][] = $value;
            } else {
                $mapped[$key] = $value;
            }
        }

        return $mapped;
    }

    private function firstResultParameterValue(array $params, string $key): mixed
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }

        $value = $params[$key];
        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    private function extractPhoneFromResultParameters(array $params): ?string
    {
        foreach (['DebitPartyName', 'CreditPartyName', 'Initiator'] as $key) {
            $value = $params[$key] ?? null;
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $candidate) {
                if (!is_string($candidate)) {
                    continue;
                }
                if (preg_match('/254\d{9}/', $candidate, $matches)) {
                    return $matches[0];
                }
            }
        }

        return null;
    }

    private function findTransactionByResultPayload(array $payload, array $params): ?MpesaTransaction
    {
        $references = array_values(array_filter([
            data_get($payload, 'Result.OriginatorConversationID'),
            data_get($payload, 'Result.ConversationID'),
            data_get($payload, 'Result.TransactionID'),
            $this->firstResultParameterValue($params, 'ReceiptNo'),
            $this->firstResultParameterValue($params, 'OriginatorConversationID'),
            $this->firstResultParameterValue($params, 'ConversationID'),
        ]));

        foreach ($references as $reference) {
            $txn = $this->findTransactionByTrackingReference((string) $reference);
            if ($txn) {
                return $txn;
            }
        }

        return null;
    }

    private function parseFlexibleMpesaDate($value): ?\DateTime
    {
        if (!$value) {
            return null;
        }

        if (is_numeric($value) || preg_match('/^\d{14}$/', (string) $value)) {
            return $this->parseMpesaDate($value);
        }

        try {
            return new \DateTime((string) $value);
        } catch (\Throwable) {
            return null;
        }
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
