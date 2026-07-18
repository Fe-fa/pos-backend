<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreManualExpenseRequest;
use App\Services\CashierShiftService;
use App\Services\TransactionDeskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionDeskController extends Controller
{
    use AuthorizesPermission;

    public function __construct(
        private readonly TransactionDeskService $transactionDeskService,
        private readonly CashierShiftService $cashierShiftService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('page.billings')) {
            return $error;
        }

        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,store_id'],
            'preset' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'flow' => ['nullable', 'string'],
            'method' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->transactionDeskService->dashboard($request->user(), $validated),
        ]);
    }

    public function storeExpense(StoreManualExpenseRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('page.billings')) {
            return $error;
        }

        $expense = $this->transactionDeskService->storeManualExpense(
            $request->user(),
            $request->validated(),
            $this->cashierShiftService,
        );

        return response()->json([
            'message' => 'Expense recorded successfully.',
            'data' => $expense,
        ], 201);
    }
}
