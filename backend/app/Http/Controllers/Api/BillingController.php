<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Requests\Billing\StoreBillingRequest;
use App\Http\Requests\Billing\UpdateBillingRequest;
use App\Models\Billing;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    use AuthorizesPermission;

    public function __construct(private readonly BillingService $service) {}

public function index(Request $request): JsonResponse
{
    $perPage = max(1, min((int) ($request->per_page ?? 11), 100));
    $user    = $request->user();

    $query = Billing::query()
        ->select([
            'billing_id',
            'uuid',
            'store_id',
            'customer_id',
            'user_id',
            'invnumber',
            'status',
            'subtotal',
            'vat_amount',
            'total',
            'paid_amount',
            'balance_due',
            'is_draft',
            'billing_date',
            'fulfillment_status',
            'fulfillment_type',
            'points_discount',
            'stock_applied_at',
            'created_at',
            'deleted_at',
        ])
        ->with([
            'customer:customer_id,full_name,email,phone',
            'store:store_id,store_name',
            'user:user_id,first_name,last_name,email',
            'payments:payment_id,billing_id,amount_received,payment_method,payment_date',
        ])
        ->withCount('items')
        ->withSum('items', 'quantity');

    if ($request->has('with_trashed') && filter_var($request->with_trashed, FILTER_VALIDATE_BOOLEAN)) {
        $query->withTrashed();
    }

    if ($request->has('only_trashed') && filter_var($request->only_trashed, FILTER_VALIDATE_BOOLEAN)) {
        $query->onlyTrashed();
    }

    $query = $this->service->scopeAccessible($query, $user);

    $query
        ->when($request->store_id, function ($q, $storeId) use ($user) {
            $this->service->authorizeStoreAccess($user, $storeId);
            $q->where('store_id', $storeId);
        })
        ->when($request->status, fn($q, $status) =>
            $q->where('status', $status)
        )
        ->when(
            $request->has('is_draft') && $request->is_draft !== '',
            fn($q) => $q->where('is_draft', filter_var($request->is_draft, FILTER_VALIDATE_BOOLEAN))
        )
        ->when($request->fulfillment_status, fn($q, $v) =>
            $q->where('fulfillment_status', $v)
        )
        ->when($request->fulfillment_type, fn($q, $v) =>
            $q->where('fulfillment_type', $v)
        )
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
        if ($error = $this->authorizePermission('billings.manage')) return $error;

        return response()->json([
            'message' => 'Draft billing created successfully.',
            'data'    => $this->service->createDraft($request->user(), $request->validated()),
        ], 201);
    }

    public function show($id): JsonResponse
    {
    
        
        $billing = Billing::withTrashed()->find($id);

        if (!$billing) {
            return response()->json([
                'message' => "Billing record #{$id} was not found in our system.",
                'data'    => null,
            ], 404);
        }

        return response()->json([
            'message' => 'Billing retrieved successfully.',
            'data'    => $this->service->show($billing),
        ]);
    }

    public function update(UpdateBillingRequest $request, $id): JsonResponse
    {
        if ($error = $this->authorizePermission('billings.manage')) return $error;

        $billing = Billing::withTrashed()->find($id);

        if (!$billing) {
            return response()->json([
                'message'    => "Update failed: Billing record #{$id} does not exist.",
                'debug_info' => 'Check if the record was deleted or if the database was refreshed.',
            ], 404);
        }

        return response()->json([
            'message' => 'Billing updated successfully.',
            'data'    => $this->service->updateHeader($billing, $request->validated()),
        ]);
    }

    public function destroy(Billing $billing): JsonResponse
    {
        if ($error = $this->authorizePermission('billings.manage')) return $error;

        $this->service->destroy($billing);

        return response()->json([
            'message' => 'Billing deleted successfully.',
        ]);
    }

    public function restore(Request $request, $id): JsonResponse
    {
        if ($error = $this->authorizePermission('billings.manage')) return $error;

        return response()->json([
            'message' => 'Billing restored successfully.',
            'data'    => $this->service->restore($id, $request->user()),
        ]);
    }
}