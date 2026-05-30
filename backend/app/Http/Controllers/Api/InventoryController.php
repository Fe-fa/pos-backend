<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Inventory retrieved successfully.',
            'data'    => $this->service->paginate(
                $request->user(),
                $request->only('store_id', 'search', 'per_page')
            ),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Inventory history retrieved successfully.',
            'data'    => $this->service->paginateHistory(
                $request->user(),
                $request->only('store_id', 'product_id', 'change_type', 'search', 'per_page')
            ),
        ]);
    }

    public function store(StoreInventoryRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Inventory created successfully.',
            'data'    => $this->service->create($request->user(), $request->validated()),
        ], 201);
    }

    public function show(Inventory $inventory): JsonResponse
    {
        return response()->json([
            'message' => 'Inventory retrieved successfully.',
            'data'    => $this->service->show($inventory),
        ]);
    }

    public function update(UpdateInventoryRequest $request, Inventory $inventory): JsonResponse
    {
        return response()->json([
            'message' => 'Inventory updated successfully.',
            'data'    => $this->service->update($request->user(), $inventory, $request->validated()),
        ]);
    }

    public function destroy(Inventory $inventory): JsonResponse
    {
        $this->service->delete($inventory);

        return response()->json([
            'message' => 'Inventory deleted successfully.',
        ]);
    }

    public function consumeFifo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id'   => ['required', 'exists:stores,store_id'],
            'product_id' => ['required', 'exists:products,product_id'],
            'quantity'   => ['required', 'integer', 'min:1'],
            'reference'  => ['nullable', 'string', 'max:100'],
            'reason'     => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'message' => 'Inventory consumed successfully using FIFO.',
            'data'    => $this->service->consumeFifo(
                user: $request->user(),
                storeId: (int) $data['store_id'],
                productId: (int) $data['product_id'],
                quantity: (int) $data['quantity'],
                reason: $data['reason'] ?? 'FIFO stock out',
                reference: $data['reference'] ?? null,
            ),
        ]);
    }
}
