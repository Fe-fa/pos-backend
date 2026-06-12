<?php

use Illuminate\Support\Str;

return [

    // 1. Encrypt session payload — you're storing sensitive POS data
'encrypt' => env('SESSION_ENCRYPT', true),  // was: false

// 2. Secure cookies — enforce HTTPS in production
'secure' => env('SESSION_SECURE_COOKIE', true),  // was: null (unset)

// 3. Tighten SameSite — 'strict' since your frontend is same-domain
'same_site' => env('SESSION_SAME_SITE', 'strict'),  // was: 'lax'

];
