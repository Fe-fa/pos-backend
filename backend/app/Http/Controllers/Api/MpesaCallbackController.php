<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mpesa\MpesaB2bService;
use App\Services\Mpesa\MpesaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{
    public function __construct(
        private readonly MpesaService $service,
        private readonly MpesaB2bService $b2bService,
    ) {
    }

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

    public function b2bResult(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack();
        }

        try {
            $payload = $request->all();
            Log::info('[Mpesa][B2B] Result callback received', $payload);
            $this->b2bService->handleResultCallback($payload);
        } catch (\Throwable $e) {
            Log::error('[Mpesa][B2B] Result callback error', ['error' => $e->getMessage()]);
        }

        return $this->ack();
    }

    public function b2bTimeout(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack();
        }

        try {
            $payload = $request->all();
            Log::warning('[Mpesa][B2B] Timeout callback received', $payload);
            $this->b2bService->handleTimeoutCallback($payload);
        } catch (\Throwable $e) {
            Log::error('[Mpesa][B2B] Timeout callback error', ['error' => $e->getMessage()]);
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
