<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    private function allowedStoreIds($user)
    {
        return $user->stores()
            ->pluck('stores.store_id')
            ->push($user->default_store_id)
            ->filter()
            ->unique()
            ->values();
    }

    private function authorizeStoreAccess($user, $storeId): void
    {
        if (!$storeId || $user->isAdmin()) {
            return;
        }

        $allowed = $this->allowedStoreIds($user)->map(fn ($id) => (string) $id)->all();

        if (!in_array((string) $storeId, $allowed, true)) {
            abort(response()->json([
                'message' => 'You are not allowed to access this store.',
            ], 403));
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = max(1, min((int) $request->get('per_page', 10), 100));

        $q = Customer::query()
            ->orderByDesc('customer_id');

        if (!$user->isAdmin()) {
            $q->whereIn('store_id', $this->allowedStoreIds($user));
        }

        if ($request->filled('store_id')) {
            $this->authorizeStoreAccess($user, $request->store_id);
            $q->where('store_id', $request->store_id);
        }

        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            $q->where(function ($w) use ($s) {
                $w->where('full_name', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            });
        }

        return response()->json([
            'message' => 'Customers retrieved successfully.',
            'data' => $q->simplePaginate($perPage)->withQueryString(),
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorizeStoreAccess($request->user(), $request->validated('store_id'));

        $customer = Customer::create($request->validated());

        return response()->json([
            'message' => 'Customer created successfully.',
            'data' => $customer,
        ], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorizeStoreAccess(request()->user(), $customer->store_id);

        return response()->json([
            'message' => 'Customer retrieved successfully.',
            'data' => $customer->loadCount('billings'),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeStoreAccess($request->user(), $customer->store_id);
        $this->authorizeStoreAccess($request->user(), $request->validated('store_id'));

        $customer->update($request->validated());

        return response()->json([
            'message' => 'Customer updated successfully.',
            'data' => $customer->fresh(),
        ]);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorizeStoreAccess(request()->user(), $customer->store_id);

        if ($customer->billings()->exists()) {
            return response()->json([
                'message' => 'Cannot delete customer with existing billings.',
            ], 422);
        }

        $customer->delete();

        return response()->json(['message' => 'Customer deleted successfully.']);
    }
}
