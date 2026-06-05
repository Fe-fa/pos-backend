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
        $perPage = max(1, min((int) $request->get('per_page', 1), 50));
        $query = Customer::query()
            ->withSum(['billings as dynamic_balance' => function ($subQuery) {
                $subQuery->where('status', '!=', 'paid');
            }], 'balance_due');

        if (!$user->isAdmin()) {
            $query->whereIn('store_id', $this->allowedStoreIds($user));
        }

        if ($request->filled('store_id')) {
            $this->authorizeStoreAccess($user, $request->store_id);
            $query->where('store_id', $request->store_id);
        }
        $query->when($request->search, function ($q, $search) {
            $s = trim((string) $search);
            $q->where(function ($w) use ($s) {
                $w->where('full_name', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            });
        });

        $query->orderByDesc('customer_id');

        $customers = $query->paginate($perPage);

        $formattedItems = collect($customers->items())->map(function (Customer $customer) {
            $customer->current_balance = round((float) ($customer->dynamic_balance ?? 0.00), 2);
            unset($customer->dynamic_balance);
            return $customer;
        });

        return response()->json([
            'data' => $formattedItems,
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page'    => $customers->lastPage(),
                'per_page'     => $customers->perPage(),
                'total'        => $customers->total(),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorizeStoreAccess($request->user(), $request->validated('store_id'));

        $customer = Customer::create($request->validated());
        $customer->current_balance = 0.00;

        return response()->json([
            'message' => 'Customer created successfully.',
            'data' => $customer,
        ], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorizeStoreAccess(request()->user(), $customer->store_id);
        $customer->loadSum(['billings as dynamic_balance' => function ($query) {
            $query->where('status', '!=', 'paid');
        }], 'balance_due');

        $customer->loadCount('billings');
        
        $customer->current_balance = round((float) ($customer->dynamic_balance ?? 0.00), 2);
        unset($customer->dynamic_balance);

        return response()->json([
            'message' => 'Customer retrieved successfully.',
            'data' => $customer,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeStoreAccess($request->user(), $customer->store_id);
        $this->authorizeStoreAccess($request->user(), $request->validated('store_id'));

        $customer->update($request->validated());

        $updatedCustomer = $customer->fresh();
        
        $updatedCustomer->loadSum(['billings as dynamic_balance' => function ($query) {
            $query->where('status', '!=', 'paid');
        }], 'balance_due');

        $updatedCustomer->current_balance = round((float) ($updatedCustomer->dynamic_balance ?? 0.00), 2);
        unset($updatedCustomer->dynamic_balance);

        return response()->json([
            'message' => 'Customer updated successfully.',
            'data' => $updatedCustomer,
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