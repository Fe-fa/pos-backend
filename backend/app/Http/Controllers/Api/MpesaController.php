<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mpesa\InitiateStkPushRequest;
use App\Http\Requests\Mpesa\ValidateManualReceiptRequest;
use App\Models\Billing;
use App\Models\Grn;
use App\Models\MpesaTransaction;
use App\Services\Mpesa\MpesaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly MpesaService $service)
    {
    }

    public function initiateStkPush(InitiateStkPushRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        $data = $request->validated();
        $user = $request->user();

        try {
            if (!empty($data['billing_id'])) {
                $billing = Billing::findOrFail($data['billing_id']);
                $txn = $this->service->initiateStkPushForBilling(
                    $billing,
                    $user,
                    $data['phone'],
                    $data['amount'] ?? null,
                    $data['split_allocations'] ?? [],
                    (int) ($data['points_redeemed'] ?? 0)
                );
            } else {
                $grn = Grn::findOrFail($data['grn_id']);
                $txn = $this->service->initiateStkPushForGrn(
                    $grn,
                    $user,
                    $data['phone'],
                    $data['amount'] ?? null
                );
            }

            return response()->json([
                'message' => 'STK Push sent. Ask the customer to enter their M-Pesa PIN.',
                'data' => [
                    'mpesa_transaction_id' => $txn->mpesa_transaction_id,
                    'checkout_request_id' => $txn->checkout_request_id,
                    'status' => $txn->status,
                    'amount' => (float) $txn->amount,
                    'phone_number' => $txn->phone_number,
                    'polling' => [
                        'interval_ms' => (int) config('mpesa.polling.interval_ms'),
                        'timeout_ms' => (int) config('mpesa.polling.timeout_ms'),
                    ],
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[Mpesa] STK push failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function status(string $checkoutRequestId): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        return response()->json([
            'data' => $this->service->status($checkoutRequestId),
        ]);
    }

    public function validateReceipt(ValidateManualReceiptRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        $data = $request->validated();

        try {
            $billing = Billing::findOrFail($data['billing_id']);
            $txn = $this->service->validateManualReceipt(
                $billing,
                $request->user(),
                $data['mpesa_receipt'],
                (float) $data['amount'],
                $data['phone'] ?? null
            );

            return response()->json([
                'message' => 'M-Pesa receipt validated and payment recorded.',
                'data' => [
                    'mpesa_transaction_id' => $txn->mpesa_transaction_id,
                    'status' => $txn->status,
                    'mpesa_receipt' => $txn->mpesa_receipt,
                    'payment_id' => $txn->payment_id,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(string $checkoutRequestId, Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('payments.charge')) return $error;

        $txn = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)->first();
        if (!$txn) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        if (!in_array($txn->status, ['pending', 'sent'], true)) {
            return response()->json(['message' => 'Transaction is already ' . $txn->status . '.'], 422);
        }

        $txn->update([
            'status' => 'cancelled',
            'result_desc' => 'Manually cancelled by cashier',
        ]);

        return response()->json(['message' => 'Transaction cancelled.']);
    }
}
