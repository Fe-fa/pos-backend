<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $orders = $this->service->list($request->user(), $request->all());

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ],
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Purchase order draft saved successfully.',
            'data' => $this->service->createDraft($request->user(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json([
            'data' => $this->service->show($request->user(), $purchaseOrder),
        ]);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json([
            'message' => 'Purchase order updated successfully.',
            'data' => $this->service->update($request->user(), $purchaseOrder, $request->validated()),
        ]);
    }

    public function place(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json([
            'message' => 'Purchase order placed successfully.',
            'data' => $this->service->place($request->user(), $purchaseOrder),
        ]);
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->service->delete($request->user(), $purchaseOrder);

        return response()->json([
            'message' => 'Purchase order deleted successfully.',
        ]);
    }
}
