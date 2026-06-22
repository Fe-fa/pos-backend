<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesPermission;
use App\Http\Requests\User\StoreAssignmentRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class UserController extends Controller
{
    use AuthorizesPermission;

    public function index(Request $request): JsonResponse
    {
        $actor            = $request->user();
        $requestedStoreId = $this->requestedStoreId($request);
        $perPage          = max(1, min((int) ($request->per_page ?? 6), 100));

        $query = User::query()
            ->select('users.*')
            ->selectSub($this->todayPaymentsSubQuery($actor, $requestedStoreId), 'sales_today')
            ->with(['defaultStore', 'stores'])
            ->orderByDesc('user_id');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('shift_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', (string) $request->input('role'));
        }

        if ($actor->isManager()) {
            $allowedStoreIds = $this->allowedStoreIds($actor);
            $query->where('role', User::ROLE_CASHIER)
                ->where(function ($builder) use ($allowedStoreIds) {
                    $builder->whereIn('default_store_id', $allowedStoreIds)
                        ->orWhereHas('stores', fn ($relation) => $relation->whereIn('stores.store_id', $allowedStoreIds));
                });
        }

        if ($requestedStoreId !== null) {
            if ($actor->isManager() && ! in_array($requestedStoreId, $this->allowedStoreIds($actor), true)) {
                return response()->json(['message' => 'You do not have access to this store.'], 403);
            }

            $query->where(function ($builder) use ($requestedStoreId) {
                $builder->where('default_store_id', $requestedStoreId)
                    ->orWhereHas('stores', fn ($relation) => $relation->where('stores.store_id', $requestedStoreId));
            });
        }

        if ($request->filled('assigned')) {
            if ($request->input('assigned') === 'assigned') {
                $query->where(function ($builder) {
                    $builder->whereNotNull('default_store_id')
                        ->orWhereHas('stores');
                });
            }

            if ($request->input('assigned') === 'unassigned') {
                $query->whereNull('default_store_id')
                    ->whereDoesntHave('stores');
            }
        }

        $users = $query->paginate($perPage);

        return response()->json([
            'data' => collect($users->items())
                ->map(fn (User $user) => $this->transformUser($user, $actor, $requestedStoreId))
                ->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
                'from'         => $users->firstItem(),
                'to'           => $users->lastItem(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        if ($error = $this->authorizePermission('users.manage')) return $error;

        $actor = $request->user();
        $data  = $request->validated();

        if ($actor->isManager()) {
            $data['role'] = User::ROLE_CASHIER;
        }

        if ($actor->isManager() && $data['role'] !== User::ROLE_CASHIER) {
            return response()->json(['message' => 'Managers can create cashiers only.'], 403);
        }

        $storeIds       = Arr::pull($data, 'store_ids', []);
        $defaultStoreId = Arr::get($data, 'default_store_id');

        $this->assertStoreScope($actor, $storeIds);

        if (($data['role'] ?? null) === User::ROLE_ADMIN) {
            $data['shift_name']  = null;
            $data['shift_start'] = null;
            $data['shift_end']   = null;
        }

        $user = User::create([
            ...$data,
            'default_store_id'  => $defaultStoreId ?: ($storeIds[0] ?? null),
            'is_active'         => $data['is_active'] ?? true,
            'is_verified'       => false,
            'email_verified_at' => app()->environment('local') ? now() : null,
        ]);

        $user->syncRoles([$user->role]);
        $this->syncUserStores($user, $storeIds);

        return response()->json([
            'message' => 'User created successfully.',
            'user'    => $this->transformUser(
                $user->fresh(['defaultStore', 'stores']),
                $actor
            ),
        ], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $actor            = $request->user();
        $requestedStoreId = $this->requestedStoreId($request);

        if (! $this->canManageUser($actor, $user)) {
            return response()->json(['message' => 'You do not have access to this user.'], 403);
        }

        $user->load(['defaultStore', 'stores']);

        return response()->json([
            'user' => $this->transformUser($user, $actor, $requestedStoreId),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        if ($error = $this->authorizePermission('users.manage')) return $error;

        $actor = $request->user();

        if (! $this->canManageUser($actor, $user)) {
            return response()->json(['message' => 'You do not have access to this user.'], 403);
        }

        $data     = $request->validated();
        $storeIds = Arr::pull($data, 'store_ids', null);

        if ($actor->isManager()) {
            $data['role'] = User::ROLE_CASHIER;
        }

        if (array_key_exists('password', $data) && empty($data['password'])) {
            unset($data['password']);
        }

        if (isset($data['role']) && $user->role !== $data['role']) {
            if ($actor->isManager() && $data['role'] !== User::ROLE_CASHIER) {
                return response()->json(['message' => 'Managers can update cashiers only.'], 403);
            }

            $user->syncRoles([$data['role']]);
        }

        if (($data['role'] ?? $user->role) === User::ROLE_ADMIN) {
            $data['shift_name']  = null;
            $data['shift_start'] = null;
            $data['shift_end']   = null;
        }

        if ($storeIds !== null) {
            $this->assertStoreScope($actor, $storeIds);
            $data['default_store_id'] = $data['default_store_id'] ?? ($storeIds[0] ?? null);
        }

        $user->update($data);

        if ($storeIds !== null) {
            $this->syncUserStores($user, $storeIds);
        }

        return response()->json([
            'message' => 'User updated successfully.',
            'user'    => $this->transformUser(
                $user->fresh(['defaultStore', 'stores']),
                $actor
            ),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($error = $this->authorizePermission('users.manage')) return $error;

        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'You do not have access to this user.'], 403);
        }

        $user->update(['is_active' => false]);

        return response()->json([
            'message' => 'User deactivated successfully.',
        ]);
    }

    public function syncStores(StoreAssignmentRequest $request, User $user): JsonResponse
    {
        if ($error = $this->authorizePermission('stores.assign')) return $error;

        $actor = $request->user();

        if (! $this->canManageUser($actor, $user)) {
            return response()->json(['message' => 'You are not authorized to manage this user.'], 403);
        }

        $storeIds = array_values(array_unique($request->validated('store_ids')));

        $this->assertStoreScope($actor, $storeIds);
        $this->syncUserStores($user, $storeIds);

        $user->update([
            'default_store_id' => $storeIds[0] ?? null,
        ]);

        return response()->json([
            'message' => 'Store assignment updated successfully.',
            'user'    => $this->transformUser(
                $user->fresh(['defaultStore', 'stores']),
                $actor
            ),
        ]);
    }

    private function canManageUser(User $actor, User $target): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        if (! $actor->isManager()) {
            return false;
        }

        if (! $target->isCashier()) {
            return false;
        }

        $allowedStoreIds = $this->allowedStoreIds($actor);

        if ($target->default_store_id && in_array((int) $target->default_store_id, $allowedStoreIds, true)) {
            return true;
        }

        return $target->stores()
            ->whereIn('stores.store_id', $allowedStoreIds)
            ->exists();
    }

    private function allowedStoreIds(User $actor): array
    {
        return $actor->stores()
            ->pluck('stores.store_id')
            ->push($actor->default_store_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function assertStoreScope(User $actor, array $storeIds): void
    {
        if ($actor->isAdmin() || empty($storeIds)) {
            return;
        }

        $allowedStoreIds = $this->allowedStoreIds($actor);
        $unauthorized    = array_diff(array_map('intval', $storeIds), $allowedStoreIds);

        if (! empty($unauthorized)) {
            abort(403, 'You do not have permission to assign one or more selected stores.');
        }
    }

    private function syncUserStores(User $user, array $storeIds): void
    {
        $payload = collect($storeIds)
            ->filter()
            ->unique()
            ->mapWithKeys(fn ($storeId) => [(int) $storeId => ['assigned_at' => now()]])
            ->all();

        $user->stores()->sync($payload);
    }

    private function requestedStoreId(Request $request): ?int
    {
        return $request->filled('store_id')
            ? (int) $request->input('store_id')
            : null;
    }

    private function todayPaymentsSubQuery(User $actor, ?int $storeId = null): Builder
    {
        $query = Payment::query()
            ->selectRaw('COALESCE(SUM(payments.amount_received), 0)')
            ->join('billing as billing_for_payments', 'billing_for_payments.billing_id', '=', 'payments.billing_id')
            ->whereColumn('billing_for_payments.user_id', 'users.user_id')
            ->whereDate('payments.payment_date', today());

        if ($storeId !== null) {
            $query->where('billing_for_payments.store_id', $storeId);
            return $query;
        }

        if (! $actor->isAdmin()) {
            $query->whereIn('billing_for_payments.store_id', $this->allowedStoreIds($actor));
        }

        return $query;
    }

    private function resolveSalesToday(User $user, ?User $actor = null, ?int $storeId = null): float
    {
        if (array_key_exists('sales_today', $user->getAttributes())) {
            return round((float) $user->getAttribute('sales_today'), 2);
        }

        $query = Payment::query()
            ->selectRaw('COALESCE(SUM(payments.amount_received), 0)')
            ->join('billing as billing_for_payments', 'billing_for_payments.billing_id', '=', 'payments.billing_id')
            ->where('billing_for_payments.user_id', $user->user_id)
            ->whereDate('payments.payment_date', today());

        if ($storeId !== null) {
            $query->where('billing_for_payments.store_id', $storeId);
        } elseif ($actor && ! $actor->isAdmin()) {
            $query->whereIn('billing_for_payments.store_id', $this->allowedStoreIds($actor));
        }

        return round((float) $query->value(\Illuminate\Support\Facades\DB::raw('COALESCE(SUM(payments.amount_received), 0)')), 2);
    }

    private function normalizeTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = (string) $value;

        return strlen($value) >= 5 ? substr($value, 0, 5) : $value;
    }

    private function buildShiftLabel(?string $name, ?string $start, ?string $end): ?string
    {
        $name = trim((string) $name);

        if ($name !== '' && $start && $end) {
            return "{$name} ({$start} - {$end})";
        }

        if ($name !== '') {
            return $name;
        }

        if ($start && $end) {
            return "{$start} - {$end}";
        }

        return null;
    }

    private function transformUser(User $user, ?User $actor = null, ?int $storeId = null): array
    {
        $user->loadMissing(['defaultStore', 'stores']);

        $shiftStart = $this->normalizeTime($user->shift_start);
        $shiftEnd   = $this->normalizeTime($user->shift_end);
        $shiftLabel = $this->buildShiftLabel($user->shift_name, $shiftStart, $shiftEnd);
        $salesToday = $this->resolveSalesToday($user, $actor, $storeId);

        return [
            'user_id'          => $user->user_id,
            'username'         => $user->username,
            'first_name'       => $user->first_name,
            'last_name'        => $user->last_name,
            'full_name'        => $user->full_name,
            'email'            => $user->email,
            'phone'            => $user->phone,
            'role'             => $user->role,
            'is_active'        => $user->is_active,

            'shift_name'       => $user->shift_name,
            'shift_start'      => $shiftStart,
            'shift_end'        => $shiftEnd,
            'shift_label'      => $shiftLabel,
            'shift'            => $shiftLabel ? [
                'name'  => $user->shift_name,
                'start' => $shiftStart,
                'end'   => $shiftEnd,
                'label' => $shiftLabel,
            ] : null,

            'sales_today'         => $salesToday,
            'today_sales_amount'  => $salesToday,

            'default_store_id'  => $user->default_store_id,
            'store_id'          => $user->defaultStore?->store_id,
            'store_name'        => $user->defaultStore?->store_name,
            'location'          => $user->defaultStore?->location,
            'currency'          => $user->defaultStore?->currency,
            'default_currency'  => $user->defaultStore?->currency,

            'default_store' => $user->defaultStore ? [
                'store_id'   => $user->defaultStore->store_id,
                'store_name' => $user->defaultStore->store_name,
                'currency'   => $user->defaultStore->currency,
                'location'   => $user->defaultStore->location,
            ] : null,

            'stores' => $user->stores->map(fn ($store) => [
                'store_id'   => $store->store_id,
                'store_name' => $store->store_name,
                'currency'   => $store->currency,
                'location'   => $store->location,
            ])->values()->all(),

            'roles'       => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}