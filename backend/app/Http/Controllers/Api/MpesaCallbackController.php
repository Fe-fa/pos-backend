<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mpesa\MpesaB2bService;
use App\Services\Mpesa\MpesaService;
use App\Services\Mpesa\RealtimeC2BPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaCallbackController extends Controller
{
    public function __construct(
        private readonly MpesaService $service,
        private readonly MpesaB2bService $b2bService,
        private readonly RealtimeC2BPaymentService $realtimeService,
    ) {
    }

    public function stk(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack('Accepted');
        }

        try {
            $payload = $request->all();
            Log::info('[Mpesa] STK callback received', $payload);
            $this->service->handleStkCallback($payload);
        } catch (\Throwable $e) {
            Log::error('[Mpesa] STK callback error', ['error' => $e->getMessage()]);
        }

        return $this->ack('Accepted');
    }

    public function c2bValidation(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack('Accepted');
        }

        try {
            $result = $this->service->handleC2bValidation($request->all());
            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('[Mpesa] C2B validation error', ['error' => $e->getMessage()]);
            return $this->ack('Accepted');
        }
    }

    public function c2bConfirmation(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack('Confirmation received successfully');
        }

        $payload = $request->all();
        Log::info('[Mpesa] C2B confirmation received', $payload);

        app()->terminating(function () use ($payload) {
            try {
                $this->realtimeService->processIncomingConfirmation($payload);
            } catch (\Throwable $e) {
                Log::error('[Mpesa] C2B confirmation processing error', [
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                ]);
            }
        });

        return $this->ack('Confirmation received successfully');
    }

    public function b2bResult(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack('Accepted');
        }

        try {
            $payload = $request->all();
            Log::info('[Mpesa][B2B] Result callback received', $payload);
            $this->b2bService->handleResultCallback($payload);
        } catch (\Throwable $e) {
            Log::error('[Mpesa][B2B] Result callback error', ['error' => $e->getMessage()]);
        }

        return $this->ack('Accepted');
    }

    public function b2bTimeout(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack('Accepted');
        }

        try {
            $payload = $request->all();
            Log::warning('[Mpesa][B2B] Timeout callback received', $payload);
            $this->b2bService->handleTimeoutCallback($payload);
        } catch (\Throwable $e) {
            Log::error('[Mpesa][B2B] Timeout callback error', ['error' => $e->getMessage()]);
        }

        return $this->ack('Accepted');
    }

    public function txStatusResult(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack('Accepted');
        }

        try {
            $payload = $request->all();
            Log::info('[Mpesa] Transaction Status result', $payload);
            $this->service->handleTransactionStatusResult($payload);
        } catch (\Throwable $e) {
            Log::error('[Mpesa] Transaction Status result error', ['error' => $e->getMessage()]);
        }

        return $this->ack('Accepted');
    }

    public function txStatusTimeout(Request $request): JsonResponse
    {
        if ($request->attributes->get('mpesa_untrusted')) {
            return $this->ack('Accepted');
        }

        try {
            $payload = $request->all();
            Log::warning('[Mpesa] Transaction Status timeout', $payload);
            $this->service->handleTransactionStatusTimeout($payload);
        } catch (\Throwable $e) {
            Log::error('[Mpesa] Transaction Status timeout error', ['error' => $e->getMessage()]);
        }

        return $this->ack('Accepted');
    }

    private function ack(string $message): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => $message]);
    }
}
