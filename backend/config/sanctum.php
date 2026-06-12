<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

'expiration' => env('SANCTUM_EXPIRATION', 60), // 60 minutes

'refresh_expiration' => env('SANCTUM_REFRESH_EXPIRATION', 60 * 24 * 30), // 30 days

'session_lifetime' => env('SANCTUM_SESSION_LIFETIME', 60), // 60 minutes

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];