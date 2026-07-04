<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mpesa\MpesaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MpesaCallbackController — public endpoints hit by Safaricom.
 *
 * IMPORTANT: We ALWAYS return { ResultCode: 0, ResultDesc: "Accepted" } with
 * HTTP 200, even on internal errors. Otherwise Safaricom retries relentlessly.
 * Untrusted / malformed callbacks are ignored inside the try/catch.
 */
class MpesaCallbackController extends Controller
{
    public function __construct(private readonly MpesaService $service) {}

    public function stk(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack();
        }

        try {
            $payload = $request->all();
            Log::info('[Mpesa] STK callback received', $payload);
            $this->service->handleStkCallback($payload);
        } catch (\Throwable $e) {
            Log::error('[Mpesa] STK callback error', ['error' => $e->getMessage()]);
        }

        return $this->ack();
    }

    public function c2bValidation(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack();
        }

        try {
            $result = $this->service->handleC2bValidation($request->all());
            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('[Mpesa] C2B validation error', ['error' => $e->getMessage()]);
            return $this->ack();
        }
    }

    public function c2bConfirmation(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack();
        }

        try {
            $payload = $request->all();
            Log::info('[Mpesa] C2B confirmation received', $payload);
            $this->service->handleC2bConfirmation($payload);
        } catch (\Throwable $e) {
            Log::error('[Mpesa] C2B confirmation error', ['error' => $e->getMessage()]);
        }

        return $this->ack();
    }

    public function txStatusResult(Request $request): JsonResponse
    {
        Log::info('[Mpesa] Transaction Status result', $request->all());
        return $this->ack();
    }

    public function txStatusTimeout(Request $request): JsonResponse
    {
        Log::warning('[Mpesa] Transaction Status timeout', $request->all());
        return $this->ack();
    }

    private function ack(): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
