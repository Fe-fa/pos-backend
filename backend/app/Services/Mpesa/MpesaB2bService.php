<?php

namespace App\Services\Mpesa;

use App\Models\MpesaTransaction;
use App\Models\PaymentVoucher;
use App\Models\Store;
use App\Services\PaymentVoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class MpesaB2bService
{
    public function __construct(
        private readonly PaymentVoucherService $voucherService,
    ) {
    }

    public function resolveCredentialsForStore(Store $store): array
    {
        $decrypt = static function (?string $value): ?string {
            if (!$value) {
                return null;
            }

            try {
                return decrypt($value);
            } catch (\Throwable) {
                return $value;
            }
        };

        return [
            'consumer_key' => $decrypt($store->mpesa_consumer_key ?? null) ?? config('mpesa.b2b.consumer_key') ?? config('mpesa.consumer_key'),
            'consumer_secret' => $decrypt($store->mpesa_consumer_secret ?? null) ?? config('mpesa.b2b.consumer_secret') ?? config('mpesa.consumer_secret'),
            'initiator_name' => $decrypt($store->mpesa_initiator_name ?? null) ?? config('mpesa.b2b.initiator_name'),
            'initiator_password' => $decrypt($store->mpesa_initiator_password ?? null) ?? config('mpesa.b2b.initiator_password'),
            'security_credential' => $decrypt($store->mpesa_security_credential ?? null) ?? config('mpesa.b2b.security_credential'),
            'sender_shortcode' => $store->mpesa_b2b_shortcode ?? $store->mpesa_shortcode ?? config('mpesa.b2b.sender_shortcode') ?? config('mpesa.shortcode'),
            'environment' => $store->mpesa_environment ?? config('mpesa.environment', 'sandbox'),
            'callback_base_url' => $store->mpesa_callback_base_url ?? config('mpesa.callback_base_url') ?? config('app.url'),
            'certificate_path' => config('mpesa.b2b.certificate_path'),
        ];
    }

    public function normalizePhoneNumber(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        $digits = ltrim((string) $digits, '0');

        if (str_starts_with($digits, '254')) {
            $digits = $digits;
        } elseif (strlen($digits) === 9 && preg_match('/^[71]\d{8}$/', $digits)) {
            $digits = '254' . $digits;
        } else {
            throw new RuntimeException('Supplier M-Pesa phone must be normalized to 2547XXXXXXXX or 2541XXXXXXXX.');
        }

        if (!preg_match('/^254[71]\d{8}$/', $digits)) {
            throw new RuntimeException('Supplier M-Pesa phone must be normalized to 2547XXXXXXXX or 2541XXXXXXXX.');
        }

        return $digits;
    }

    public function initiate(Store $store, array $attributes): array
    {
        $creds = $this->resolveCredentialsForStore($store);
        $phone = $this->normalizePhoneNumber((string) ($attributes['phone_number'] ?? ''));

        // Safaricom requires a unique OriginatorConversationID per B2C request.
        // Generate it up front so it can be persisted on the transaction row
        // even if the HTTP call below fails, keeping callback matching consistent.
        $originatorConversationId = (string) ($attributes['originator_conversation_id'] ?? (string) Str::uuid());

        $requestPayload = $this->buildPayoutPayload($creds, [
            ...$attributes,
            'phone_number' => $phone,
            'originator_conversation_id' => $originatorConversationId,
        ]);
        $this->assertFloatCoverage($store, (float) ($attributes['amount'] ?? 0), (float) ($attributes['transaction_fee'] ?? 0));

        $token = $this->accessToken($creds);
        $response = Http::asJson()
            ->timeout(45)
            ->withToken($token)
            ->post($this->b2cEndpoint($creds), $requestPayload);

        if (!$response->successful()) {
            throw new RuntimeException(
                data_get($response->json(), 'errorMessage')
                ?: data_get($response->json(), 'ResponseDescription')
                ?: 'Safaricom B2C payout request failed.'
            );
        }

        return [
            'normalized_phone' => $phone,
            'originator_conversation_id' => $originatorConversationId,
            'request_payload' => $requestPayload,
            'response' => $response->json(),
        ];
    }

    public function localStatus(string $trackingReference): array
    {
        $txn = MpesaTransaction::query()
            ->where('originator_conversation_id', $trackingReference)
            ->orWhere('conversation_id', $trackingReference)
            ->orWhere('account_reference', $trackingReference)
            ->latest('mpesa_transaction_id')
            ->first();

        if (!$txn) {
            throw new RuntimeException('Supplier payout transaction not found.');
        }

        $voucher = $txn->paymentVoucher()->first();

        return [
            'mpesa_transaction_id' => $txn->mpesa_transaction_id,
            'status' => $txn->status,
            'conversation_id' => $txn->conversation_id,
            'originator_conversation_id' => $txn->originator_conversation_id,
            'result_code' => $txn->result_code,
            'result_desc' => $txn->result_desc,
            'mpesa_receipt' => $txn->mpesa_receipt,
            'amount' => (float) $txn->amount,
            'voucher_id' => $txn->payment_voucher_id,
            'phone_number' => $txn->phone_number,
            'remaining_balance' => $voucher ? $this->voucherService->remainingBalanceForVoucher($voucher) : null,
            'voucher_status' => $voucher?->status,
        ];
    }

    public function handleResultCallback(array $payload): ?MpesaTransaction
    {
        $result = data_get($payload, 'Result', []);
        $originatorConversationId = (string) data_get($result, 'OriginatorConversationID', '');
        $conversationId = (string) data_get($result, 'ConversationID', '');
        $resultCode = (int) data_get($result, 'ResultCode', 1);
        $resultDesc = (string) data_get($result, 'ResultDesc', 'Unknown result');
        $params = $this->flattenParameters(data_get($result, 'ResultParameters.ResultParameter', []));

        return DB::transaction(function () use (
            $payload,
            $originatorConversationId,
            $conversationId,
            $resultCode,
            $resultDesc,
            $params
        ) {
            $txn = MpesaTransaction::query()
                ->when($originatorConversationId !== '', fn ($q) => $q->where('originator_conversation_id', $originatorConversationId))
                ->when($originatorConversationId === '' && $conversationId !== '', fn ($q) => $q->where('conversation_id', $conversationId))
                ->lockForUpdate()
                ->latest('mpesa_transaction_id')
                ->first();

            if (!$txn) {
                Log::warning('[Mpesa][SupplierPayout] Callback could not find transaction', [
                    'originator_conversation_id' => $originatorConversationId,
                    'conversation_id' => $conversationId,
                ]);
                return null;
            }

            $receipt = (string) (
                $params['TransactionReceipt']
                ?? $params['TransactionID']
                ?? data_get($result, 'TransactionID')
                ?? $txn->mpesa_receipt
            );

            $txn->update([
                'callback_payload' => $payload,
                'conversation_id' => $conversationId ?: $txn->conversation_id,
                'originator_conversation_id' => $originatorConversationId ?: $txn->originator_conversation_id,
                'mpesa_receipt' => $receipt ?: $txn->mpesa_receipt,
                'result_code' => (string) $resultCode,
                'result_desc' => $resultDesc,
                'transaction_date' => now(),
                'status' => $resultCode === 0 ? 'success' : $this->mapFailureStatus($resultCode),
            ]);

            $voucher = $txn->paymentVoucher()->lockForUpdate()->first();
            if ($resultCode !== 0) {
                if ($voucher && strtolower((string) $voucher->status) === 'processing') {
                    $voucher->update([
                        'status' => $this->fallbackVoucherStatus($voucher, $txn),
                    ]);
                }

                return $txn->fresh();
            }

            if ($voucher) {
                $payment = $this->voucherService->recordGatewaySettlement($txn->fresh());
                if ($payment) {
                    $txn->update([
                        'payment_id' => $payment->grn_payment_id,
                    ]);
                }
            }

            return $txn->fresh();
        });
    }

    public function handleTimeoutCallback(array $payload): ?MpesaTransaction
    {
        $originatorConversationId = (string) (
            data_get($payload, 'OriginatorConversationID')
            ?: data_get($payload, 'Result.OriginatorConversationID')
            ?: ''
        );

        if ($originatorConversationId === '') {
            Log::warning('[Mpesa][SupplierPayout] Timeout callback missing OriginatorConversationID', $payload);
            return null;
        }

        return DB::transaction(function () use ($payload, $originatorConversationId) {
            $txn = MpesaTransaction::query()
                ->where('originator_conversation_id', $originatorConversationId)
                ->lockForUpdate()
                ->latest('mpesa_transaction_id')
                ->first();

            if (!$txn) {
                return null;
            }

            $txn->update([
                'status' => 'timeout',
                'result_code' => 'TIMEOUT',
                'result_desc' => 'Safaricom timeout callback received.',
                'callback_payload' => $payload,
            ]);

            $voucher = $txn->paymentVoucher()->lockForUpdate()->first();
            if ($voucher && strtolower((string) $voucher->status) === 'processing') {
                $voucher->update([
                    'status' => $this->fallbackVoucherStatus($voucher, $txn),
                ]);
            }

            return $txn->fresh();
        });
    }

    private function buildPayoutPayload(array $creds, array $attributes): array
    {
        return [
            'OriginatorConversationID' => (string) ($attributes['originator_conversation_id'] ?? (string) Str::uuid()),
            'InitiatorName' => $creds['initiator_name'],
            'Initiator' => $creds['initiator_name'],
            'SecurityCredential' => $this->securityCredential($creds),
            'CommandID' => 'BusinessPayment',
            'Amount' => (int) round((float) $attributes['amount']),
            'PartyA' => (string) $creds['sender_shortcode'],
            'PartyB' => (string) $attributes['phone_number'],
            'Remarks' => (string) ($attributes['remarks'] ?? 'Supplier payout'),
            'QueueTimeOutURL' => $this->timeoutUrl($creds),
            'ResultURL' => $this->resultUrl($creds),
            'Occasion' => (string) ($attributes['occasion'] ?? $attributes['account_reference'] ?? 'Supplier payout'),
        ];
    }

    private function accessToken(array $creds): string
    {
        $response = Http::timeout(30)
            ->withBasicAuth($creds['consumer_key'], $creds['consumer_secret'])
            ->get($this->baseUrl($creds) . '/oauth/v1/generate?grant_type=client_credentials');

        if (!$response->successful()) {
            Log::error('[Mpesa][SupplierPayout] OAuth token request failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'consumer_key_prefix' => substr((string) $creds['consumer_key'], 0, 6),
            ]);

            throw new RuntimeException(
                data_get($response->json(), 'errorMessage')
                ?: data_get($response->json(), 'error_description')
                ?: "Unable to generate Daraja OAuth token (HTTP {$response->status()})."
            );
        }

        $token = data_get($response->json(), 'access_token');
        if (!$token) {
            throw new RuntimeException('Daraja OAuth token missing in response.');
        }

        return $token;
    }

    private function securityCredential(array $creds): string
    {
        $preGenerated = trim((string) ($creds['security_credential'] ?? ''));
        if ($preGenerated !== '') {
            return $preGenerated;
        }

        $certificatePath = $creds['certificate_path'] ?: storage_path(
            ($creds['environment'] ?? 'sandbox') === 'live'
                ? 'app/mpesa/ProductionCertificate.cer'
                : 'app/mpesa/SandboxCertificate.cer'
        );

        if (!is_file($certificatePath)) {
            throw new RuntimeException("M-Pesa certificate not found at {$certificatePath}");
        }

        $certificate = file_get_contents($certificatePath);
        $password = (string) ($creds['initiator_password'] ?? '');
        if ($password === '') {
            throw new RuntimeException('M-Pesa initiator password is missing.');
        }

        $encrypted = null;
        $ok = openssl_public_encrypt($password, $encrypted, $certificate, OPENSSL_PKCS1_PADDING);
        if (!$ok || !$encrypted) {
            throw new RuntimeException('Unable to generate M-Pesa SecurityCredential.');
        }

        return base64_encode($encrypted);
    }

    private function baseUrl(array $creds): string
    {
        return ($creds['environment'] ?? 'sandbox') === 'live'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    private function b2cEndpoint(array $creds): string
    {
        return rtrim((string) (config('mpesa.b2c.endpoint') ?: $this->baseUrl($creds) . '/mpesa/b2c/v3/paymentrequest'), '/');
    }

    private function resultUrl(array $creds): string
    {
        $url = rtrim((string) $creds['callback_base_url'], '/')
            . config('mpesa.callback_paths.b2b_result', '/api/webhooks/mpesa/b2b/result');

        return $this->appendCallbackToken($url);
    }

    private function timeoutUrl(array $creds): string
    {
        $url = rtrim((string) $creds['callback_base_url'], '/')
            . config('mpesa.callback_paths.b2b_timeout', '/api/webhooks/mpesa/b2b/timeout');

        return $this->appendCallbackToken($url);
    }

    private function appendCallbackToken(string $url): string
    {
        $secret = (string) config('mpesa.callback_shared_secret', '');
        if ($secret === '') {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . 'token=' . urlencode($secret);
    }

    private function flattenParameters(array $rows): array
    {
        $flat = [];
        foreach ($rows as $row) {
            $key = data_get($row, 'Key');
            if ($key === null || $key === '') {
                continue;
            }
            $flat[(string) $key] = data_get($row, 'Value');
        }
        return $flat;
    }

    private function mapFailureStatus(int $resultCode): string
    {
        return match ($resultCode) {
            1032 => 'cancelled',
            1 => 'timeout',
            default => 'failed',
        };
    }

    private function fallbackVoucherStatus(PaymentVoucher $voucher, MpesaTransaction $txn): string
    {
        $previous = strtolower((string) data_get($txn->request_payload, 'previous_voucher_status', ''));
        if (in_array($previous, ['authorized', 'partially_paid'], true)) {
            return $previous;
        }

        return (float) $voucher->paid_amount > 0 ? 'partially_paid' : 'authorized';
    }

    private function assertFloatCoverage(Store $store, float $amount, float $fee): void
    {
        $available = null;
        foreach (['mpesa_float_balance', 'mpesa_utility_float_balance', 'mpesa_available_float'] as $field) {
            $value = $store->{$field} ?? null;
            if ($value !== null && $value !== '') {
                $available = (float) $value;
                break;
            }
        }

        if ($available !== null && ($amount + $fee) > $available) {
            throw new RuntimeException('Utility organization float is below the requested amount plus transaction fees.');
        }
    }
}