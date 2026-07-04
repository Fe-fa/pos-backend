<?php

namespace App\Services\Mpesa;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * DarajaClient — thin HTTP wrapper around Safaricom's Daraja API.
 *
 * Responsibilities:
 *   - Fetch + cache OAuth access tokens (they're valid 3600s; we cache 3500s).
 *   - Assemble STK Push, STK Query, C2B Register URL, and Transaction Status
 *     request bodies.
 *   - Return decoded JSON arrays (never raw responses) to the service layer.
 *
 * Credentials are passed in per-call so multi-tenant stores each use their own
 * shortcode. Never read config('mpesa.*') directly inside this class.
 */
class DarajaClient
{
    /**
     * @param array $creds {
     *   consumer_key: string, consumer_secret: string, passkey: string,
     *   shortcode: string, shortcode_type: 'paybill'|'till',
     *   till_number?: string, environment: 'sandbox'|'production',
     *   callback_base_url: string, callback_shared_secret: string,
     * }
     */
    public function __construct(private readonly array $creds)
    {
        foreach (['consumer_key', 'consumer_secret', 'shortcode', 'environment'] as $key) {
            if (empty($this->creds[$key])) {
                throw new RuntimeException("Missing M-Pesa credential: {$key}");
            }
        }
    }

    public function environment(): string
    {
        return $this->creds['environment'];
    }

    public function shortcode(): string
    {
        return (string) $this->creds['shortcode'];
    }

    // ─────────────────────────────────────────────────────────────
    //  Auth: OAuth access token (cached)
    // ─────────────────────────────────────────────────────────────

    public function getAccessToken(): string
    {
        $cacheKey = 'mpesa:token:' . md5($this->creds['consumer_key'] . '|' . $this->creds['environment']);

        return Cache::remember($cacheKey, 3500, function () {
            $endpoint = $this->endpoint('auth');

            $response = Http::withBasicAuth(
                $this->creds['consumer_key'],
                $this->creds['consumer_secret']
            )->timeout(15)->get($endpoint);

            $this->logIfDebug('auth', ['status' => $response->status(), 'body' => $response->body()]);

            if (!$response->ok() || empty($response->json('access_token'))) {
                throw new RuntimeException(
                    'Failed to obtain M-Pesa access token: ' . $response->body()
                );
            }

            return (string) $response->json('access_token');
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  STK Push (Lipa na M-Pesa Online)
    // ─────────────────────────────────────────────────────────────

    /**
     * @param array $args {
     *   amount: int (ksh, whole numbers only per Daraja),
     *   phone: string (2547XXXXXXXX / 2541XXXXXXXX),
     *   account_reference: string (max 12 chars),
     *   transaction_desc: string (max 13 chars),
     *   callback_url: string,
     * }
     */
    public function stkPush(array $args): array
    {
        $timestamp = date('YmdHis');
        $password  = base64_encode($this->creds['shortcode'] . $this->creds['passkey'] . $timestamp);

        $isTill = ($this->creds['shortcode_type'] ?? 'paybill') === 'till';

        $body = [
            'BusinessShortCode' => $this->creds['shortcode'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            // TransactionType: CustomerPayBillOnline (paybill) OR CustomerBuyGoodsOnline (till)
            'TransactionType'   => $isTill ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
            'Amount'            => (int) round($args['amount']),
            'PartyA'            => $this->normalisePhone($args['phone']),
            // For Till (BuyGoods), PartyB should be the Till Number.
            'PartyB'            => $isTill
                ? ($this->creds['till_number'] ?? $this->creds['shortcode'])
                : $this->creds['shortcode'],
            'PhoneNumber'       => $this->normalisePhone($args['phone']),
            'CallBackURL'       => $args['callback_url'],
            'AccountReference'  => substr($args['account_reference'] ?? 'POS', 0, 12),
            'TransactionDesc'   => substr($args['transaction_desc'] ?? 'Sale', 0, 13),
        ];

        $response = $this->authedRequest()->post($this->endpoint('stk_push'), $body);
        $this->logIfDebug('stk_push', ['req' => $body, 'res' => $response->json()]);

        $json = $response->json() ?? [];
        if (!$response->ok() || ($json['ResponseCode'] ?? '1') !== '0') {
            throw new RuntimeException(
                'STK Push rejected: ' . ($json['errorMessage'] ?? $json['ResponseDescription'] ?? $response->body())
            );
        }

        return $json;
    }

    /**
     * Query the status of a previously-initiated STK Push.
     * Useful when the callback never arrives (network drop).
     */
    public function stkQuery(string $checkoutRequestId): array
    {
        $timestamp = date('YmdHis');
        $password  = base64_encode($this->creds['shortcode'] . $this->creds['passkey'] . $timestamp);

        $response = $this->authedRequest()->post($this->endpoint('stk_query'), [
            'BusinessShortCode' => $this->creds['shortcode'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);

        $this->logIfDebug('stk_query', ['id' => $checkoutRequestId, 'res' => $response->json()]);

        return $response->json() ?? [];
    }

    // ─────────────────────────────────────────────────────────────
    //  C2B — Register callback URLs (one-time per shortcode)
    // ─────────────────────────────────────────────────────────────

    public function c2bRegisterUrls(string $validationUrl, string $confirmationUrl): array
    {
        $response = $this->authedRequest()->post($this->endpoint('c2b_register'), [
            'ShortCode'       => $this->creds['shortcode'],
            'ResponseType'    => config('mpesa.c2b_response_type', 'Completed'),
            'ConfirmationURL' => $confirmationUrl,
            'ValidationURL'   => $validationUrl,
        ]);

        $this->logIfDebug('c2b_register', ['res' => $response->json()]);

        return $response->json() ?? [];
    }

    // ─────────────────────────────────────────────────────────────
    //  Transaction Status — manual receipt validation
    // ─────────────────────────────────────────────────────────────

    /**
     * Confirm a customer-provided M-Pesa receipt code out-of-band.
     *
     * Uses the Transaction Status API. Requires an "initiator" (API user)
     * configured on Daraja portal — if you don't have one yet, fall back
     * to matching against already-received C2B callbacks in the DB.
     */
    public function transactionStatus(
        string $mpesaReceipt,
        string $initiator,
        string $securityCredential,
        string $resultUrl,
        string $timeoutUrl,
        string $remarks = 'POS manual receipt validation',
        string $occasion = 'ManualValidation'
    ): array {
        $response = $this->authedRequest()->post($this->endpoint('tx_status'), [
            'Initiator'                => $initiator,
            'SecurityCredential'       => $securityCredential,
            'CommandID'                => 'TransactionStatusQuery',
            'TransactionID'            => $mpesaReceipt,
            'PartyA'                   => $this->creds['shortcode'],
            'IdentifierType'           => '4', // Organisation shortcode
            'ResultURL'                => $resultUrl,
            'QueueTimeOutURL'          => $timeoutUrl,
            'Remarks'                  => $remarks,
            'Occasion'                 => $occasion,
        ]);

        $this->logIfDebug('tx_status', ['receipt' => $mpesaReceipt, 'res' => $response->json()]);

        return $response->json() ?? [];
    }

    // ─────────────────────────────────────────────────────────────
    //  Internal helpers
    // ─────────────────────────────────────────────────────────────

    private function authedRequest(): PendingRequest
    {
        return Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function endpoint(string $key): string
    {
        $env = $this->creds['environment'];
        $url = config("mpesa.endpoints.{$env}.{$key}");
        if (!$url) {
            throw new RuntimeException("Unknown M-Pesa endpoint [{$env}.{$key}]");
        }
        return $url;
    }

    /**
     * Normalise Kenyan phone → 2547XXXXXXXX / 2541XXXXXXXX.
     * Accepts: 07XX..., 01XX..., +2547XX..., 2547XX...
     */
    public static function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '254' . substr($digits, 1);
        }
        if (str_starts_with($digits, '7') && strlen($digits) === 9) {
            return '254' . $digits;
        }
        if (str_starts_with($digits, '1') && strlen($digits) === 9) {
            return '254' . $digits;
        }
        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            return $digits;
        }

        throw new RuntimeException("Invalid Kenyan phone number: {$phone}");
    }

    private function logIfDebug(string $event, array $ctx): void
    {
        if (config('mpesa.debug_logging')) {
            Log::channel('daily')->debug("[Daraja::{$event}]", $ctx);
        }
    }
}
