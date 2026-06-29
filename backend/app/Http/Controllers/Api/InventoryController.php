<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Requests\Inventory\AdjustInventoryRequest;
use App\Http\Requests\Inventory\StoreInventoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly InventoryService $service) {}

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('inventory.view')) return $error;

        $perPage = max(1, min((int) ($request->per_page ?? 22), 100));
        $user    = $request->user();

        $query = Inventory::query()
            ->select([
                'inventory_id',
                'store_id',
                'product_id',
                'batch_no',
                'quantity',
                'reorder_level',
                'created_at',
            ])
            ->with([
                'store:store_id,store_name',
                // ── price added so frontend can compute Total Inventory Value ──
                'product:product_id,product_name,sku,price,category_id,image_url',
                'product.category:category_id,category_name',
            ]);

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
            ->when($request->store_id, fn($q, $storeId) =>
                $q->where('store_id', $storeId)
            )
            ->when($request->search, function ($q, $search) {
                $search = trim($search);
                $q->where(function ($sub) use ($search) {
                    $sub->where('batch_no', 'like', "%{$search}%")
                        ->orWhereHas('product', fn($pq) =>
                            $pq->where('product_name', 'like', "%{$search}%")
                               ->orWhere('sku', 'like', "%{$search}%")
                        );
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
        if ($error = $this->authorizePermission('inventory.view')) return $error;

        $perPage = max(1, min((int) ($request->per_page ?? 22), 100));
        $user    = $request->user();

        $query = InventoryHistory::query()
            ->select([
                'inventory_history_id',
                'store_id',
                'product_id',
                'user_id',
                'batch_no',
                'reference',
                'change_type',
                'quantity_before',
                'quantity_changed',
                'quantity_after',
                'created_at',
            ])
            ->with([
                'store:store_id,store_name',
                'product:product_id,product_name,sku,image_url',
                'user:user_id,first_name,last_name,email',
            ]);

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
            ->when($request->store_id, fn($q, $storeId) =>
                $q->where('store_id', $storeId)
            )
            ->when($request->product_id, fn($q, $productId) =>
                $q->where('product_id', $productId)
            )
            ->when($request->change_type, fn($q, $changeType) =>
                $q->where('change_type', $changeType)
            )
            ->when($request->search, function ($q, $search) {
                $search = trim($search);
                $q->where(function ($sub) use ($search) {
                    $sub->where('batch_no', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhereHas('product', fn($pq) =>
                            $pq->where('product_name', 'like', "%{$search}%")
                               ->orWhere('sku', 'like', "%{$search}%")
                        );
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
        if ($error = $this->authorizePermission('inventory.manage')) return $error;

        return response()->json([
            'message' => 'Inventory created successfully.',
            'data'    => $this->service->create($request->user(), $request->validated()),
        ], 201);
    }

    public function show(Request $request, Inventory $inventoryItem): JsonResponse
    {
        if ($error = $this->authorizePermission('inventory.view')) return $error;

        return response()->json([
            'message' => 'Inventory retrieved successfully.',
            'data'    => $this->service->show($inventoryItem),
        ]);
    }

    public function update(UpdateInventoryRequest $request, Inventory $inventoryItem): JsonResponse
    {
        if ($error = $this->authorizePermission('inventory.manage')) return $error;

        return response()->json([
            'message' => 'Inventory updated successfully.',
            'data'    => $this->service->update($request->user(), $inventoryItem, $request->validated()),
        ]);
    }

    public function adjust(AdjustInventoryRequest $request, Inventory $inventoryItem): JsonResponse
    {
        if ($error = $this->authorizePermission('inventory.manage')) return $error;

        return response()->json([
            'message' => 'Inventory adjusted successfully.',
            'data'    => $this->service->adjust($request->user(), $inventoryItem, $request->validated()),
        ]);
    }

    public function destroy(Request $request, Inventory $inventoryItem): JsonResponse
    {
        if ($error = $this->authorizePermission('inventory.manage')) return $error;

        $this->service->delete($inventoryItem);

        return response()->json([
            'message' => 'Inventory deleted successfully.',
        ]);
    }

    public function consumeFifo(Request $request): JsonResponse
    {
        if ($error = $this->authorizePermission('inventory.manage')) return $error;

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
                user:      $request->user(),
                storeId:   (int) $data['store_id'],
                productId: (int) $data['product_id'],
                quantity:  (int) $data['quantity'],
                reason:    $data['reason'] ?? 'FIFO stock out',
                reference: $data['reference'] ?? null,
            ),
        ]);
    }
}