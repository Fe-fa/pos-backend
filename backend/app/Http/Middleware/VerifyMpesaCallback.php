<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * VerifyMpesaCallback — protects M-Pesa callback endpoints.
 *
 * Two independent layers:
 *   1. Shared-secret query token (?token=...) — configured via
 *      MPESA_CALLBACK_SHARED_SECRET and sent as part of the callback URL
 *      registered with Safaricom.
 *   2. Optional IP whitelist (MPESA_CALLBACK_IP_WHITELIST) — comma-separated
 *      CIDRs/IPs. Safaricom callback IPs are well-known in production.
 *
 * NEVER return anything other than 200 with { ResultCode: 0 } to Safaricom
 * (or they will retry endlessly). If verification fails, log and still
 * ACK — but ignore the payload in the controller.
 */
class VerifyMpesaCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('mpesa.callback_shared_secret', '');
        $provided = (string) $request->query('token', '');

        $sharedSecretOk = $expected === '' || hash_equals($expected, $provided);
        $ipOk = $this->ipAllowed($request->ip());

        if (!$sharedSecretOk || !$ipOk) {
            Log::warning('[Mpesa] Rejected callback', [
                'reason' => !$sharedSecretOk ? 'bad_token' : 'bad_ip',
                'ip'     => $request->ip(),
                'path'   => $request->path(),
            ]);

            // Still 200 so Safaricom stops retrying, but mark the request as untrusted.
            $request->attributes->set('mpesa_untrusted', true);
        }

        return $next($request);
    }

    private function ipAllowed(?string $ip): bool
    {
        $whitelist = array_filter((array) config('mpesa.callback_ip_whitelist', []));
        if (empty($whitelist) || !$ip) return true;

        foreach ($whitelist as $entry) {
            $entry = trim($entry);
            if ($entry === $ip) return true;
            if (str_contains($entry, '/') && $this->ipInCidr($ip, $entry)) return true;
        }
        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);
        return (ip2long($ip) & ~((1 << (32 - (int) $mask)) - 1)) === ip2long($subnet);
    }
}
