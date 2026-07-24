<?php

return [
    'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),

    'endpoints' => [
        'sandbox' => [
            'auth'          => 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
            'stk_push'      => 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
            'stk_query'     => 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query',
            'c2b_register'  => 'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl',
            'tx_status'     => 'https://sandbox.safaricom.co.ke/mpesa/transactionstatus/v1/query',
            'pull_register' => 'https://sandbox.safaricom.co.ke/pulltransactions/v1/register',
            'pull_query'    => 'https://sandbox.safaricom.co.ke/pulltransactions/v1/query',
            'b2b'           => 'https://sandbox.safaricom.co.ke/mpesa/b2b/v1/paymentrequest',
            'b2c'           => 'https://sandbox.safaricom.co.ke/mpesa/b2c/v3/paymentrequest',
            'balance'       => 'https://sandbox.safaricom.co.ke/mpesa/accountbalance/v1/query',
            'reversal'      => 'https://sandbox.safaricom.co.ke/mpesa/reversal/v1/request',
        ],
        'production' => [
            'auth'          => 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
            'stk_push'      => 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
            'stk_query'     => 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query',
            'c2b_register'  => 'https://api.safaricom.co.ke/mpesa/c2b/v1/registerurl',
            'tx_status'     => 'https://api.safaricom.co.ke/mpesa/transactionstatus/v1/query',
            'pull_register' => 'https://api.safaricom.co.ke/pulltransactions/v1/register',
            'pull_query'    => 'https://api.safaricom.co.ke/pulltransactions/v1/query',
            'b2b'           => 'https://api.safaricom.co.ke/mpesa/b2b/v1/paymentrequest',
            'b2c'           => 'https://api.safaricom.co.ke/mpesa/b2c/v3/paymentrequest',
            'balance'       => 'https://api.safaricom.co.ke/mpesa/accountbalance/v1/query',
            'reversal'      => 'https://api.safaricom.co.ke/mpesa/reversal/v1/request',
        ],
    ],

    'consumer_key'    => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
    'passkey'         => env('MPESA_PASSKEY'),
    'shortcode'       => env('MPESA_SHORTCODE', '174379'),
    'shortcode_type'  => env('MPESA_SHORTCODE_TYPE', 'paybill'),
    'till_number'     => env('MPESA_TILL_NUMBER'),
    'callback_base_url' => env('MPESA_CALLBACK_BASE_URL', env('APP_URL')),

    'callback_paths' => [
        'stk'               => '/api/mpesa/callbacks/stk',
        'c2b_validation'    => '/api/mpesa/callbacks/c2b/validation',
        'c2b_confirmation'  => '/api/mpesa/callbacks/c2b/confirmation',
        'tx_status_result'  => '/api/mpesa/callbacks/tx-status/result',
        'tx_status_timeout' => '/api/mpesa/callbacks/tx-status/timeout',
        'b2b_result'        => '/api/webhooks/mpesa/b2b/result',
        'b2b_timeout'       => '/api/webhooks/mpesa/b2b/timeout',
        'balance_result'    => '/api/webhooks/mpesa/balance/result',
        'balance_timeout'   => '/api/webhooks/mpesa/balance/timeout',
    ],

    'c2b_response_type' => env('MPESA_C2B_RESPONSE_TYPE', 'Completed'),
    'callback_shared_secret' => env('MPESA_CALLBACK_SHARED_SECRET'),
    'idempotency_ttl' => 90,
    'polling' => [
        'interval_ms' => 3000,
        'timeout_ms'  => 120000,
    ],
    'debug_logging' => env('MPESA_DEBUG_LOGGING', false),
    'callback_ip_whitelist' => array_filter(explode(',', (string) env('MPESA_CALLBACK_IP_WHITELIST', ''))),

    'transaction_status' => [
        'initiator_name' => env('MPESA_TX_STATUS_INITIATOR_NAME', env('MPESA_B2B_INITIATOR_NAME')),
        'security_credential' => env('MPESA_TX_STATUS_SECURITY_CREDENTIAL', env('MPESA_B2B_SECURITY_CREDENTIAL')),
    ],

    'pull' => [
        'enabled' => env('MPESA_PULL_ENABLED', true),
        'auto_register' => env('MPESA_PULL_AUTO_REGISTER', true),
        'nominated_number' => env('MPESA_PULL_NOMINATED_NUMBER'),
        'lookback_minutes' => (int) env('MPESA_PULL_LOOKBACK_MINUTES', 60),
        'max_offsets' => (int) env('MPESA_PULL_MAX_OFFSETS', 3),
        'registration_cache_ttl' => (int) env('MPESA_PULL_REGISTRATION_CACHE_TTL', 86400),
    ],

    'realtime' => [
        'wait_ttl_minutes' => (int) env('MPESA_REALTIME_WAIT_TTL_MINUTES', 5),
    ],

    'b2c' => [
        'endpoint' => env('MPESA_B2C_ENDPOINT'),
        'command_id' => env('MPESA_B2C_COMMAND_ID', 'BusinessPayment'),
        'sender_shortcode' => env('MPESA_B2C_SENDER_SHORTCODE', env('MPESA_B2B_SENDER_SHORTCODE', env('MPESA_SHORTCODE'))),
    ],

    'b2b' => [
        'consumer_key'        => env('MPESA_B2B_CONSUMER_KEY') ?: env('MPESA_CONSUMER_KEY'),
        'consumer_secret'     => env('MPESA_B2B_CONSUMER_SECRET') ?: env('MPESA_CONSUMER_SECRET'),
        'initiator_name'      => env('MPESA_B2B_INITIATOR_NAME'),
        'initiator_password'  => env('MPESA_B2B_INITIATOR_PASSWORD'),
        'sender_shortcode'    => env('MPESA_B2B_SENDER_SHORTCODE') ?: env('MPESA_SHORTCODE'),
        'security_credential' => env('MPESA_B2B_SECURITY_CREDENTIAL'),
        'endpoint'            => env('MPESA_B2B_ENDPOINT'),
        'sender_identifier_type' => env('MPESA_B2B_SENDER_IDENTIFIER_TYPE', '4'),
        'till_receiver_identifier_type' => env('MPESA_B2B_TILL_RECEIVER_IDENTIFIER_TYPE', '2'),
        'paybill_receiver_identifier_type' => env('MPESA_B2B_PAYBILL_RECEIVER_IDENTIFIER_TYPE', '4'),
        'certificate_path'    => env(
            'MPESA_B2B_CERTIFICATE_PATH',
            storage_path(
                in_array(env('MPESA_ENVIRONMENT', 'sandbox'), ['live', 'production'], true)
                    ? 'app/mpesa/ProductionCertificate.cer'
                    : 'app/mpesa/SandboxCertificate.cer'
            )
        ),
    ],

    'balance' => [
        'consumer_key' => env('MPESA_BALANCE_CONSUMER_KEY', env('MPESA_B2B_CONSUMER_KEY', env('MPESA_CONSUMER_KEY'))),
        'consumer_secret' => env('MPESA_BALANCE_CONSUMER_SECRET', env('MPESA_B2B_CONSUMER_SECRET', env('MPESA_CONSUMER_SECRET'))),
        'initiator_name' => env('MPESA_BALANCE_INITIATOR_NAME', env('MPESA_B2B_INITIATOR_NAME')),
        'security_credential' => env('MPESA_BALANCE_SECURITY_CREDENTIAL', env('MPESA_B2B_SECURITY_CREDENTIAL')),
        'shortcode' => env('MPESA_BALANCE_SHORTCODE', env('MPESA_B2B_SENDER_SHORTCODE', env('MPESA_SHORTCODE'))),
        'endpoint' => env('MPESA_BALANCE_ENDPOINT'),
        'identifier_type' => env('MPESA_BALANCE_IDENTIFIER_TYPE', '4'),
        'preferred_account' => env('MPESA_BALANCE_PREFERRED_ACCOUNT', 'utility'),
        'max_age_seconds' => (int) env('MPESA_BALANCE_MAX_AGE_SECONDS', 300),
        'auto_request_if_missing' => filter_var(env('MPESA_BALANCE_AUTO_REQUEST_IF_MISSING', true), FILTER_VALIDATE_BOOL),
        'auto_request_if_stale' => filter_var(env('MPESA_BALANCE_AUTO_REQUEST_IF_STALE', true), FILTER_VALIDATE_BOOL),
        'require_sufficient_before_payout' => filter_var(env('MPESA_BALANCE_REQUIRE_SUFFICIENT_BEFORE_PAYOUT', true), FILTER_VALIDATE_BOOL),
    ],
];