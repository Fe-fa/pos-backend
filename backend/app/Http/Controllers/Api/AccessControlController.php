<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccessControl\AssignUserRoleRequest;
use App\Http\Requests\AccessControl\UpdateRolePermissionsRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlController extends Controller
{
    private const GUARD = 'sanctum';

    private const ALL_ROLES = [
        User::ROLE_ADMIN,
        User::ROLE_MANAGER,
        User::ROLE_CASHIER,
    ];

    private const EDITABLE_ROLES = [
        User::ROLE_MANAGER,
        User::ROLE_CASHIER,
    ];

    public function index(): JsonResponse
    {
        $permissions = Permission::query()
            ->where('guard_name', self::GUARD)
            ->orderBy('name')
            ->get()
            ->map(fn ($permission) => [
                'name' => $permission->name,
                'label' => Str::of($permission->name)->replace('.', ' ')->title()->toString(),
            ])
            ->values();

        $roles = Role::query()
            ->where('guard_name', self::GUARD)
            ->whereIn('name', self::ALL_ROLES)
            ->with('permissions:id,name')
            ->get()
            ->sortBy(fn ($role) => array_search($role->name, self::ALL_ROLES, true))
            ->values()
            ->map(fn ($role) => [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values(),
            ])
            ->values();

        $users = User::query()
            ->with([
                'stores:store_id,store_name',
                'roles:id,name',
            ])
            ->orderByDesc('user_id')
            ->get()
            ->map(function ($user) {
                $fullName = $user->full_name
                    ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                    ?: ($user->name ?? $user->email);

                return [
                    'user_id' => $user->user_id,
                    'full_name' => $fullName,
                    'email' => $user->email,
                    'role' => $user->roles->pluck('name')->first() ?? $user->role ?? null,
                    'stores' => $user->stores->map(fn ($store) => [
                        'store_id' => $store->store_id,
                        'store_name' => $store->store_name,
                    ])->values(),
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Access control data retrieved successfully.',
            'permissions' => $permissions,
            'roles' => $roles,
            'users' => $users,
        ]);
    }

    public function updateRolePermissions(
        UpdateRolePermissionsRequest $request,
        string $roleName
    ): JsonResponse {
        if (!in_array($roleName, self::EDITABLE_ROLES, true)) {
            return response()->json([
                'message' => 'Only manager and cashier role templates can be edited.',
            ], 422);
        }

        try {
            $validated = $request->validated();

            $role = Role::findByName($roleName, self::GUARD);
            $role->syncPermissions($validated['permissions'] ?? []);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $role->load('permissions:id,name');

            return response()->json([
                'message' => ucfirst($roleName) . ' permissions updated successfully.',
                'data' => [
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')->sort()->values(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update role permissions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function assignUserRole(
        AssignUserRoleRequest $request,
        User $user
    ): JsonResponse {
        try {
            $roleName = $request->validated()['role'];
            $role = Role::findByName($roleName, self::GUARD);

            $user->syncRoles([$role]);

            if ($user->isFillable('role')) {
                $user->role = $role->name;
                $user->save();
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return response()->json([
                'message' => 'User role assigned successfully.',
                'data' => [
                    'user_id' => $user->user_id,
                    'role' => $role->name,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to assign user role.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
