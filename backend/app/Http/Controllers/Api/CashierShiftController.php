<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CashierShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierShiftController extends Controller
{
    public function __construct(private readonly CashierShiftService $cashierShiftService)
    {
    }

    public function today(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
            'business_date' => ['nullable', 'date'],
        ]);

        return response()->json([
            'data' => $this->cashierShiftService->getTodayForUser(
                $request->user(),
                (int) $validated['store_id'],
                $validated['business_date'] ?? null,
            ),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
            'business_date' => ['nullable', 'date'],
            'opening_balance' => ['required', 'numeric', 'min:0'],
            'opening_note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'message' => 'Cashier shift opened successfully.',
            'data' => $this->cashierShiftService->openShift(
                $request->user(),
                (int) $validated['store_id'],
                (float) $validated['opening_balance'],
                $validated['opening_note'] ?? null,
                $validated['business_date'] ?? null,
            ),
        ], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
            'business_date' => ['nullable', 'date'],
            'cashier_user_id' => ['nullable', 'integer'],
            'counted_cash' => ['nullable', 'numeric', 'min:0'],
            'close_note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'message' => 'Cashier shift closed successfully.',
            'data' => $this->cashierShiftService->closeShift(
                $request->user(),
                (int) $validated['store_id'],
                isset($validated['cashier_user_id']) ? (int) $validated['cashier_user_id'] : null,
                isset($validated['counted_cash']) ? (float) $validated['counted_cash'] : null,
                $validated['close_note'] ?? null,
                $validated['business_date'] ?? null,
            ),
        ]);
    }

    public function dailySales(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
            'cashier_user_id' => ['nullable', 'integer'],
            'business_date' => ['nullable', 'date'],
        ]);

        $cashierUserId = $validated['cashier_user_id'] ?? $request->user()->user_id;

        return response()->json([
            'data' => $this->cashierShiftService->getCashierDailySales(
                $request->user(),
                (int) $validated['store_id'],
                (int) $cashierUserId,
                $validated['business_date'] ?? null,
            ),
        ]);
    }

    public function allCashiers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
            'business_date' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        if (!$user->isAdmin() && !$user->isManager()) {
            abort(403, 'Only managers and admins can view all cashiers report.');
        }

        $payload = $this->cashierShiftService->buildScopedDailySummary(
            [(int) $validated['store_id']],
            $validated['business_date'] ?? null,
        );

        return response()->json(['data' => $payload]);
    }

    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
            'business_date' => ['nullable', 'date'],
        ]);

        $this->cashierShiftService->getTodayForUser(
            $request->user(),
            (int) $validated['store_id'],
            $validated['business_date'] ?? null,
        );

        return response()->json([
            'data' => $this->cashierShiftService->buildScopedDailySummary(
                [(int) $validated['store_id']],
                $validated['business_date'] ?? null,
            ),
        ]);
    }

    public function cashDrop(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer'],
            'cashier_user_id' => ['nullable', 'integer'],
            'business_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'message' => 'Cash drop recorded successfully.',
            'data' => $this->cashierShiftService->recordCashDrop(
                $request->user(),
                (int) $validated['store_id'],
                (float) $validated['amount'],
                $validated['note'] ?? null,
                isset($validated['cashier_user_id']) ? (int) $validated['cashier_user_id'] : null,
                $validated['business_date'] ?? null,
            ),
        ], 201);
    }
}
