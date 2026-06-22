<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CustomerController extends Controller
{
    use AuthorizesPermission;

    // ─── FIX: Cache a plain int[] instead of a Collection object ─────────────
    //
    // ROOT CAUSE of "__PHP_Incomplete_Class returned":
    //   The old code cached the result of ->values() which is an Eloquent
    //   Collection. Laravel serializes it with PHP's serialize(), embedding
    //   the fully-qualified class name. On a cache hit, PHP calls unserialize()
    //   — if the class isn't autoloaded at that exact moment (fresh deploy,
    //   worker restart, opcache mismatch) it produces __PHP_Incomplete_Class
    //   instead of throwing, silently breaking the return-type contract.
    //
    // FIX:
    //   Cache ->toArray() — a plain PHP int[]. Scalar arrays survive any
    //   PHP/framework version change without class resolution.
    //   On read, wrap in collect() here so every caller still receives a
    //   Collection and nothing else in the file needs changing.
    // ─────────────────────────────────────────────────────────────────────────
    private function allowedStoreIds($user): Collection
    {
        $ids = Cache::remember(
            "user_store_ids_{$user->user_id}",
            now()->addMinutes(5),
            fn () => $user->stores()
                ->pluck('stores.store_id')
                ->push($user->default_store_id)
                ->filter()
                ->unique()
                ->values()
                ->map(fn ($id) => (int) $id)
                ->toArray()              // ← store plain int[], not a Collection
        );

        // If a stale cache entry somehow still holds an object, recover cleanly.
        if (!is_array($ids)) {
            Cache::forget("user_store_ids_{$user->user_id}");
            return $this->allowedStoreIds($user);
        }

        return collect($ids);            // ← always returns a real Collection
    }

    private function authorizeStoreAccess($user, $storeId): void
    {
        if (!$storeId || $user->isAdmin()) {
            return;
        }

        $allowed = $this->allowedStoreIds($user)
            ->map(fn ($id) => (string) $id)
            ->all();

        if (!in_array((string) $storeId, $allowed, true)) {
            abort(response()->json([
                'message' => 'You are not allowed to access this store.',
            ], 403));
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = max(1, min((int) $request->get('per_page', 6), 50));

        $query = Customer::query()
            ->select([
                'customer_id',
                'store_id',
                'full_name',
                'phone',
                'email',
                'current_balance',
                'loyalty_points',
                'total_earned_points',
                'punch_card_count',
                'total_free_items_earned',
                'created_at',
            ])
            ->withSum(
                ['billings as current_balance' => fn ($q) => $q
                    ->where('status', '!=', 'paid')
                    ->where('is_draft', false)
                ],
                'balance_due'
            );

        if (!$user->isAdmin()) {
            $query->whereIn('store_id', $this->allowedStoreIds($user));
        }

        if ($request->filled('store_id')) {
            $this->authorizeStoreAccess($user, $request->store_id);
            $query->where('store_id', (int) $request->store_id);
        }

        $query->when($request->search, function ($q, $search) {
            $s = str_replace(['%', '_'], ['\%', '\_'], trim((string) $search));
            $q->where(function ($w) use ($s) {
                $w->where('full_name', 'like', "%{$s}%")
                  ->orWhere('phone',   'like', "%{$s}%")
                  ->orWhere('email',   'like', "%{$s}%");
            });
        });

        $query->orderByDesc('customer_id');

        $customers = $query->paginate($perPage);
        $items = collect($customers->items())->map(function (Customer $c) {
            $c->current_balance = round((float) ($c->current_balance ?? 0.00), 2);
            return $c;
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page'    => $customers->lastPage(),
                'per_page'     => $customers->perPage(),
                'total'        => $customers->total(),
                'from'         => $customers->firstItem(),
                'to'           => $customers->lastItem(),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('customers.manage')) return $error;

        $this->authorizeStoreAccess($request->user(), $request->validated('store_id'));

        $customer = Customer::create($request->validated());
        $customer->current_balance = 0.00;

        return response()->json([
            'message' => 'Customer created successfully.',
            'data'    => $customer,
        ], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorizeStoreAccess(request()->user(), $customer->store_id);

        $customer->loadSum(
            ['billings as current_balance' => fn ($q) => $q
                ->where('status', '!=', 'paid')
                ->where('is_draft', false)
            ],
            'balance_due'
        );
        $customer->loadCount('billings');

        $customer->current_balance = round((float) ($customer->current_balance ?? 0.00), 2);

        return response()->json([
            'message' => 'Customer retrieved successfully.',
            'data'    => $customer,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        if ($error = $this->authorizePermission('customers.manage')) return $error;

        $this->authorizeStoreAccess($request->user(), $customer->store_id);
        $this->authorizeStoreAccess($request->user(), $request->validated('store_id'));

        $customer->update($request->validated());

        $customer->loadSum(
            ['billings as current_balance' => fn ($q) => $q
                ->where('status', '!=', 'paid')
                ->where('is_draft', false)
            ],
            'balance_due'
        );

        $customer->current_balance = round((float) ($customer->current_balance ?? 0.00), 2);

        return response()->json([
            'message' => 'Customer updated successfully.',
            'data'    => $customer,
        ]);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        if ($error = $this->authorizePermission('customers.manage')) return $error;

        $this->authorizeStoreAccess(request()->user(), $customer->store_id);

        if ($customer->billings()->exists()) {
            return response()->json([
                'message' => 'Cannot delete customer with existing billings.',
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'message' => 'Customer deleted successfully.',
        ]);
    }
}