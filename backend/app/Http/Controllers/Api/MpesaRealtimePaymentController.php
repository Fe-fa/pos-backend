<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivePaymentAttempt;
use App\Models\Billing;
use App\Models\MpesaTransaction;
use App\Models\Store;
use App\Models\UnassignedMpesaPayment;
use App\Services\Mpesa\RealtimeC2BPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MpesaRealtimePaymentController extends Controller
{
    public function __construct(private readonly RealtimeC2BPaymentService $service)
    {
    }

    public function registerUrls(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
        ]);

        $store = Store::query()->findOrFail($data['store_id']);
        $response = $this->service->registerC2BUrlsForStore($store);

        return response()->json([
            'message' => 'C2B URLs registered successfully.',
            'data' => $response,
        ]);
    }

    public function startWaitingAttempt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'billing_id' => ['required', 'integer'],
            'terminal_id' => ['required', 'string', 'max:120'],
            'expected_amount' => ['required', 'numeric', 'min:0.01'],
            'split_allocations' => ['nullable', 'array'],
            'points_redeemed' => ['nullable', 'integer', 'min:0'],
        ]);

        $billing = Billing::query()->findOrFail($data['billing_id']);
        $attempt = $this->service->startWaitingAttempt(
            $billing,
            $request->user(),
            $data['terminal_id'],
            (float) $data['expected_amount'],
            $data['split_allocations'] ?? [],
            (int) ($data['points_redeemed'] ?? 0),
        );

        return response()->json([
            'message' => 'Waiting for till payment.',
            'data' => [
                'active_payment_attempt_id' => $attempt->active_payment_attempt_id,
                'billing_id' => $attempt->billing_id,
                'terminal_id' => $attempt->terminal_id,
                'status' => $attempt->status,
                'expected_amount' => (float) $attempt->expected_amount,
                'expires_at' => optional($attempt->expires_at)->toIso8601String(),
                'bill_channel' => 'bill.' . $attempt->billing_id,
            ],
        ]);
    }

    public function cancelWaitingAttempt(Request $request, ActivePaymentAttempt $attempt): JsonResponse
    {
        $attempt = $this->service->cancelWaitingAttempt($attempt, $request->user());

        return response()->json([
            'message' => 'Waiting attempt cancelled.',
            'data' => $attempt,
        ]);
    }

    public function claimPayment(Request $request, MpesaTransaction $transaction): JsonResponse
    {
        $data = $request->validate([
            'terminal_id' => ['required', 'string', 'max:120'],
        ]);

        $txn = $this->service->claimConflictPayment($transaction, $request->user(), $data['terminal_id']);

        return response()->json([
            'message' => 'Payment claimed successfully.',
            'data' => [
                'status' => 'success',
                'billing_id' => $txn->billing_id,
                'payment_id' => $txn->payment_id,
                'amount' => (float) $txn->amount,
                'phone_number' => $txn->phone_number,
                'mpesa_receipt' => $txn->mpesa_receipt,
                'mpesa_transaction_id' => $txn->mpesa_transaction_id,
            ],
        ]);
    }

    public function unassignedIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
        ]);

        return response()->json([
            'data' => $this->service->listUnassignedPayments((int) $data['store_id']),
        ]);
    }

    public function applyUnassigned(Request $request, UnassignedMpesaPayment $unassigned): JsonResponse
    {
        $data = $request->validate([
            'billing_id' => ['required', 'integer'],
            'terminal_id' => ['required', 'string', 'max:120'],
        ]);

        $billing = Billing::query()->findOrFail($data['billing_id']);
        $txn = $this->service->applyUnassignedPayment($unassigned, $billing, $request->user(), $data['terminal_id']);

        return response()->json([
            'message' => 'Unassigned payment applied.',
            'data' => [
                'status' => 'success',
                'billing_id' => $txn->billing_id,
                'payment_id' => $txn->payment_id,
                'amount' => (float) $txn->amount,
                'phone_number' => $txn->phone_number,
                'mpesa_receipt' => $txn->mpesa_receipt,
                'mpesa_transaction_id' => $txn->mpesa_transaction_id,
            ],
        ]);
    }
}
