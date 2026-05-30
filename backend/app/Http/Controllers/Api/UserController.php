<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreAssignmentRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $query = User::query()->with(['defaultStore', 'stores'])->orderByDesc('user_id');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        if ($actor->isManager()) {
            $allowedStoreIds = $this->allowedStoreIds($actor);
            $query->where('role', User::ROLE_CASHIER)
                ->where(function ($builder) use ($allowedStoreIds) {
                    $builder->whereIn('default_store_id', $allowedStoreIds)
                        ->orWhereHas('stores', fn ($relation) => $relation->whereIn('stores.store_id', $allowedStoreIds));
                });
        }

        if ($request->filled('store_id')) {
            $storeId = (int) $request->input('store_id');
            if ($actor->isManager() && ! in_array($storeId, $this->allowedStoreIds($actor), true)) {
                return response()->json(['message' => 'You do not have access to this store.'], 403);
            }

            $query->where(function ($builder) use ($storeId) {
                $builder->where('default_store_id', $storeId)
                    ->orWhereHas('stores', fn ($relation) => $relation->where('stores.store_id', $storeId));
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
                $query->whereNull('default_store_id')->whereDoesntHave('stores');
            }
        }

        return response()->json([
            'data' => $query->get()->map(fn (User $user) => $this->transformUser($user))->values(),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validated();

        if ($actor->isManager()) {
            $data['role'] = User::ROLE_CASHIER;
        }

        if (! $actor->isAdmin() && ! $actor->isManager()) {
            return response()->json(['message' => 'You are not allowed to create users.'], 403);
        }

        if ($actor->isManager() && $data['role'] !== User::ROLE_CASHIER) {
            return response()->json(['message' => 'Managers can create cashiers only.'], 403);
        }

        $storeIds = Arr::pull($data, 'store_ids', []);
        $defaultStoreId = Arr::get($data, 'default_store_id');
        $this->assertStoreScope($actor, $storeIds);

        $user = User::create([
            ...$data,
            'default_store_id' => $defaultStoreId ?: ($storeIds[0] ?? null),
            'is_active' => $data['is_active'] ?? true,
            'is_verified' => false,
            'email_verified_at' => app()->environment('local') ? now() : null,
        ]);

        $user->syncRoles([$user->role]);
        $this->syncUserStores($user, $storeIds);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $this->transformUser($user->fresh(['defaultStore', 'stores'])),
        ], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'You do not have access to this user.'], 403);
        }

        $user->load(['defaultStore', 'stores']);

        return response()->json([
            'user' => $this->transformUser($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (! $this->canManageUser($actor, $user)) {
            return response()->json(['message' => 'You do not have access to this user.'], 403);
        }

        $data = $request->validated();
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
            'user' => $this->transformUser($user->fresh(['defaultStore', 'stores'])),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
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
    $actor = $request->user();
    if (! $actor->isAdmin()) {
        if (! $actor->hasPermissionTo('stores.assign')) {
            return response()->json(['message' => 'You do not have permission to assign stores.'], 403);
        }
        if (! $this->canManageUser($actor, $user)) {
            return response()->json(['message' => 'You are not authorized to manage this user.'], 403);
        }
    }
    $storeIds = array_values(array_unique($request->validated('store_ids')));
    $this->assertStoreScope($actor, $storeIds);
    $this->syncUserStores($user, $storeIds);
    $user->update(['default_store_id' => $storeIds[0] ?? null]);

    return response()->json([
        'message' => 'Store assignment updated successfully.',
        'user' => $this->transformUser($user->fresh(['defaultStore', 'stores'])),
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

        return $target->stores()->whereIn('stores.store_id', $allowedStoreIds)->exists();
    }

    private function allowedStoreIds(User $actor): array
    {
        return $actor->stores()->pluck('stores.store_id')
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
        $unauthorized = array_diff(array_map('intval', $storeIds), $allowedStoreIds);

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

    private function transformUser(User $user): array
    {
        $user->loadMissing(['defaultStore', 'stores']);

        return [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'default_store_id' => $user->default_store_id,
            'default_store' => $user->defaultStore ? [
                'store_id' => $user->defaultStore->store_id,
                'store_name' => $user->defaultStore->store_name,
                'currency' => $user->defaultStore->currency,
                'location' => $user->defaultStore->location,
            ] : null,
            'stores' => $user->stores->map(fn ($store) => [
                'store_id' => $store->store_id,
                'store_name' => $store->store_name,
                'currency' => $store->currency,
                'location' => $store->location,
            ])->values()->all(),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
