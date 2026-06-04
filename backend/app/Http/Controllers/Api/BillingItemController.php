<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BillingItem\StoreBillingItemRequest;
use App\Http\Requests\BillingItem\UpdateBillingItemRequest;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Services\BillingItemService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingItemController extends Controller
{
    public function __construct(
        private readonly BillingItemService $service,
        private readonly AuditLogService $auditLogService
    ) {}

    public function index(Request $request, Billing $billing): JsonResponse
    {
        $perPage = max(1, min((int) $request->get('per_page', 10), 100));

        $query = $billing->items()
            ->with('product.category');
            
        if ($request->boolean('only_trashed')) {
            $query->onlyTrashed();
        } elseif ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        // Search modifier template logic
        $query->when($request->search, function ($q, $search) {
            $search = trim($search);
            $q->whereHas('product', function ($sub) use ($search) {
                $sub->where('product_name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        });

        $query->orderByDesc('billing_item_id');

        $billingItems = $query->paginate($perPage);

        // Retain tracking log features within the application layer
        $this->auditLogService->log(
            'billing_item.view',
            $billing,
            null,
            null,
            [
                'items_count' => count($billingItems->items()),
                'with_trashed' => $request->boolean('with_trashed'),
                'only_trashed' => $request->boolean('only_trashed'),
            ],
            $billing->store_id
        );

        return response()->json([
            'data' => $billingItems->items(),
            'meta' => [
                'current_page' => $billingItems->currentPage(),
                'last_page'    => $billingItems->lastPage(),
                'per_page'     => $billingItems->perPage(),
                'total'        => $billingItems->total(),
            ],
        ]);
    }

    public function store(StoreBillingItemRequest $request, Billing $billing): JsonResponse
    {
        return response()->json([
            'message' => 'Billing item created successfully.',
            'data' => $this->service->addItem($billing, $request->validated()),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $billingItem = BillingItem::withTrashed()
            ->with(['product.category', 'billing'])
            ->findOrFail($id);

        return response()->json([
            'message' => 'Billing item retrieved successfully.',
            'data' => $billingItem,
        ]);
    }

    public function update(UpdateBillingItemRequest $request, BillingItem $billingItem): JsonResponse
    {
        return response()->json([
            'message' => 'Billing item updated successfully.',
            'data' => $this->service->updateItem($billingItem, $request->validated()),
        ]);
    }

    public function destroy(BillingItem $billingItem): JsonResponse
    {
        $this->service->deleteItem($billingItem);

        return response()->json([
            'message' => 'Billing item moved to trash successfully.',
        ]);
    }

    public function restore(int $id): JsonResponse
    {
        $billingItem = BillingItem::withTrashed()->findOrFail($id);

        return response()->json([
            'message' => 'Billing item restored successfully.',
            'data' => $this->service->restoreItem($billingItem),
        ]);
    }
}