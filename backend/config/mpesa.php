<?php

/**
 * config/mpesa.php
 *
 * Central M-Pesa Daraja config. Any store row may override these defaults
 * (see MpesaService::resolveCredentialsForStore).
 *
 * NEVER hard-code credentials in this file — they belong in .env.
 */

return [

    // 'sandbox' or 'production' — controls Safaricom base URL.
    'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),

    'endpoints' => [
        'sandbox' => [
            'auth'          => 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
            'stk_push'      => 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
            'stk_query'     => 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query',
            'c2b_register'  => 'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl',
            'tx_status'     => 'https://sandbox.safaricom.co.ke/mpesa/transactionstatus/v1/query',
        ],
        'production' => [
            'auth'          => 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
            'stk_push'      => 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
            'stk_query'     => 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query',
            'c2b_register'  => 'https://api.safaricom.co.ke/mpesa/c2b/v1/registerurl',
            'tx_status'     => 'https://api.safaricom.co.ke/mpesa/transactionstatus/v1/query',
        ],
    ],

    // Default credentials — used when a store doesn't override them.
    'consumer_key'    => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),

    // For STK Push: Lipa Na M-Pesa Online passkey (Daraja portal → your app).
    'passkey'         => env('MPESA_PASSKEY'),

    // Default shortcode when none is set on the store row.
    // For sandbox: 174379 (Paybill) is the common test shortcode.
    'shortcode'       => env('MPESA_SHORTCODE', '174379'),
    'shortcode_type'  => env('MPESA_SHORTCODE_TYPE', 'paybill'), // paybill|till
    'till_number'     => env('MPESA_TILL_NUMBER'), // optional; only when shortcode_type=till

    // Public HTTPS base URL Safaricom can reach (ngrok URL in dev).
    // Example: https://abcd1234.ngrok-free.app
    'callback_base_url' => env('MPESA_CALLBACK_BASE_URL', env('APP_URL')),

    // Paths appended to callback_base_url (must match your api.php routes).
    'callback_paths' => [
        'stk'              => '/api/mpesa/callbacks/stk',
        'c2b_validation'   => '/api/mpesa/callbacks/c2b/validation',
        'c2b_confirmation' => '/api/mpesa/callbacks/c2b/confirmation',
        'tx_status_result' => '/api/mpesa/callbacks/tx-status/result',
        'tx_status_timeout'=> '/api/mpesa/callbacks/tx-status/timeout',
    ],

    // C2B response type when validation URL is unreachable: Cancelled|Completed
    // Use 'Completed' unless you actively use validation URL.
    'c2b_response_type' => env('MPESA_C2B_RESPONSE_TYPE', 'Completed'),

    // Shared secret Safaricom must present to authenticate callbacks.
    // Passed via URL query string ?token=... — see MpesaCallbackSignature middleware.
    'callback_shared_secret' => env('MPESA_CALLBACK_SHARED_SECRET'),

    // Idempotency window: how long a pending STK request blocks new attempts
    // on the same billing (seconds). Safaricom STK auto-expires after 60s.
    'idempotency_ttl' => 90,

    // Poll settings for the frontend "payment in progress" modal.
    'polling' => [
        'interval_ms' => 3000, // frontend polls every 3s
        'timeout_ms'  => 120000, // give up after 2 minutes
    ],

    // Log verbose Daraja request/response bodies to the daily log channel.
    'debug_logging' => env('MPESA_DEBUG_LOGGING', false),

    // Whitelisted Safaricom IP ranges for callback firewalling (optional).
    // If empty, IP filter is skipped. Enable in production for defense-in-depth.
    'callback_ip_whitelist' => array_filter(explode(',', (string) env('MPESA_CALLBACK_IP_WHITELIST', ''))),
];
