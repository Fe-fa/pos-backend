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
        $perPage = (int) ($request->per_page ?? 10);
        $user = $request->user();

        // 1. Initialize Base Query
        $query = Inventory::query()
            ->with(['store', 'product.category']);

        // 2. Mandatory Multi-Tenant Security Logic
        if (!$user->isAdmin() && !$user->can('stores.manage')) {
            $storeIds = $user->stores()->pluck('stores.store_id')
                ->push($user->default_store_id)
                ->filter()
                ->unique();

            $query->whereIn('store_id', $storeIds);
        }

        // 3. Conditional Filtering matching your layout structure
        $query->when($request->store_id, function ($q, $storeId) {
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
            ->orderBy('inventory_id'); // Match original service sort criteria

        // 4. Paginate
        $inventories = $query->paginate($perPage);

        // 5. Output response strictly wrapped matching specified structure
        return response()->json([
            'data' => $inventories->items(),
            'meta' => [
                'current_page' => $inventories->currentPage(),
                'last_page'    => $inventories->lastPage(),
                'per_page'     => $inventories->perPage(),
                'total'        => $inventories->total(),
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $perPage = (int) ($request->per_page ?? 10);
        $user = $request->user();

        // 1. Initialize History Query
        $query = InventoryHistory::query()
            ->with(['store', 'product', 'user']);

        // 2. Mandatory Multi-Tenant Security Logic
        if (!$user->isAdmin() && !$user->can('stores.manage')) {
            $storeIds = $user->stores()->pluck('stores.store_id')
                ->push($user->default_store_id)
                ->filter()
                ->unique();

            $query->whereIn('store_id', $storeIds);
        }

        // 3. Conditional Filters matching design template
        $query->when($request->store_id, function ($q, $storeId) {
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
            ->orderByDesc('inventory_history_id'); // Match historical inverse tracking

        // 4. Execute pagination query
        $histories = $query->paginate($perPage);

        // 5. Package back standard matching response envelope
        return response()->json([
            'data' => $histories->items(),
            'meta' => [
                'current_page' => $histories->currentPage(),
                'last_page'    => $histories->lastPage(),
                'per_page'     => $histories->perPage(),
                'total'        => $histories->total(),
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