<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, (int) ($request->per_page ?? 10));
        $user = $request->user();

        $query = Inventory::query()
            ->with(['store', 'product.category']);

        if (!$user->isAdmin() && !$user->can('stores.manage')) {
            $storeIds = $user->stores()
                ->pluck('stores.store_id')
                ->push($user->default_store_id)
                ->filter()
                ->unique()
                ->values();

            $query->whereIn('store_id', $storeIds);
        }

        $query
            ->when($request->store_id, function ($q, $storeId) {
                $q->where('store_id', $storeId);
            })
            ->when($request->search, function ($q, $search) {
                $search = trim($search);

                $q->where(function ($sub) use ($search) {
                    $sub->whereHas('product', function ($pq) use ($search) {
                        $pq->where('product_name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    })->orWhere('batch_no', 'like', "%{$search}%");
                });
            })
            ->orderBy('product_id')
            ->orderBy('created_at')
            ->orderBy('inventory_id');

        $inventories = $query->paginate($perPage);

        return response()->json([
            'data' => $inventories->items(),
            'meta' => [
                'current_page' => $inventories->currentPage(),
                'last_page'    => $inventories->lastPage(),
                'per_page'     => $inventories->perPage(),
                'total'        => $inventories->total(),
                'from'         => $inventories->firstItem(),
                'to'           => $inventories->lastItem(),
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $perPage = max(1, (int) ($request->per_page ?? 10));
        $user = $request->user();

        $query = InventoryHistory::query()
            ->with(['store', 'product', 'user']);

        if (!$user->isAdmin() && !$user->can('stores.manage')) {
            $storeIds = $user->stores()
                ->pluck('stores.store_id')
                ->push($user->default_store_id)
                ->filter()
                ->unique()
                ->values();

            $query->whereIn('store_id', $storeIds);
        }

        $query
            ->when($request->store_id, function ($q, $storeId) {
                $q->where('store_id', $storeId);
            })
            ->when($request->product_id, function ($q, $productId) {
                $q->where('product_id', $productId);
            })
            ->when($request->change_type, function ($q, $changeType) {
                $q->where('change_type', $changeType);
            })
            ->when($request->search, function ($q, $search) {
                $search = trim($search);

                $q->where(function ($sub) use ($search) {
                    $sub->whereHas('product', function ($pq) use ($search) {
                        $pq->where('product_name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    })
                    ->orWhere('batch_no', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('inventory_history_id');

        $histories = $query->paginate($perPage);

        return response()->json([
            'data' => $histories->items(),
            'meta' => [
                'current_page' => $histories->currentPage(),
                'last_page'    => $histories->lastPage(),
                'per_page'     => $histories->perPage(),
                'total'        => $histories->total(),
                'from'         => $histories->firstItem(),
                'to'           => $histories->lastItem(),
            ],
        ]);
    }

    public function store(StoreInventoryRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Inventory created successfully.',
            'data'    => $this->service->create($request->user(), $request->validated()),
        ], 201);
    }

public function show(Inventory $inventoryItem): JsonResponse
{
    return response()->json([
        'message' => 'Inventory retrieved successfully.',
        'data'    => $this->service->show($inventoryItem),
    ]);
}

public function update(UpdateInventoryRequest $request, Inventory $inventoryItem): JsonResponse
{
    return response()->json([
        'message' => 'Inventory updated successfully.',
        'data'    => $this->service->update($request->user(), $inventoryItem, $request->validated()),
    ]);
}

public function destroy(Inventory $inventoryItem): JsonResponse
{
    $this->service->delete($inventoryItem);

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
