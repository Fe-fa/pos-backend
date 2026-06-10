<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreBillingRequest;
use App\Http\Requests\Billing\UpdateBillingRequest;
use App\Models\Billing;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private readonly BillingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) ($request->per_page ?? 10), 100));
        $user = $request->user();

        $query = Billing::query()
            ->with(['customer', 'store', 'user', 'payments'])
            ->withCount('items')
            ->withSum('items', 'quantity');

        if ($request->has('with_trashed') && filter_var($request->with_trashed, FILTER_VALIDATE_BOOLEAN)) {
            $query->withTrashed();
        }

        if ($request->has('only_trashed') && filter_var($request->only_trashed, FILTER_VALIDATE_BOOLEAN)) {
            $query->onlyTrashed();
        }
        $query = $this->service->scopeAccessible($query, $user);
        $query->when($request->store_id, function ($q, $storeId) use ($user) {
                $this->service->authorizeStoreAccess($user, $storeId);
                $q->where('store_id', $storeId);
            })
            ->when($request->status, function ($q, $status) {
                $q->where('status', $status);
            })
            ->when($request->has('is_draft') && $request->is_draft !== '', function ($q) use ($request) {
                $q->where('is_draft', filter_var($request->is_draft, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->fulfillment_status, function ($q, $fulfillmentStatus) {
                $q->where('fulfillment_status', $fulfillmentStatus);
            })
            ->when($request->fulfillment_type, function ($q, $fulfillmentType) {
                $q->where('fulfillment_type', $fulfillmentType);
            })
            ->orderByDesc('billing_id');
        $billings = $query->paginate($perPage);

        return response()->json([
            'data' => $billings->items(),
            'meta' => [
                'current_page' => $billings->currentPage(),
                'last_page'    => $billings->lastPage(),
                'per_page'     => $billings->perPage(),
                'total'        => $billings->total(),
                'from'         => $billings->firstItem(), 
                'to'           => $billings->lastItem(),
            ],
        ]);
    }

    public function store(StoreBillingRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Draft billing created successfully.',
            'data' => $this->service->createDraft($request->user(), $request->validated()),
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $billing = Billing::withTrashed()->find($id);

        if (!$billing) {
            return response()->json([
                'message' => "Billing record #{$id} was not found in our system.",
                'data' => null,
            ], 404);
        }

        return response()->json([
            'message' => 'Billing retrieved successfully.',
            'data' => $this->service->show($billing),
        ]);
    }

    public function update(UpdateBillingRequest $request, $id): JsonResponse
    {
        $billing = Billing::withTrashed()->find($id);

        if (!$billing) {
            return response()->json([
                'message' => "Update failed: Billing record #{$id} does not exist.",
                'debug_info' => 'Check if the record was deleted or if the database was refreshed.',
            ], 404);
        }

        return response()->json([
            'message' => 'Billing updated successfully.',
            'data' => $this->service->updateHeader($billing, $request->validated()),
        ]);
    }

    public function destroy(Billing $billing): JsonResponse
    {
        $this->service->destroy($billing);

        return response()->json([
            'message' => 'Billing deleted successfully.',
        ]);
    }

    public function restore(Request $request, $id): JsonResponse
    {
        return response()->json([
            'message' => 'Billing restored successfully.',
            'data' => $this->service->restore($id, $request->user()),
        ]);
    }
}