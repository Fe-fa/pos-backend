<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccessControl\AssignUserRoleRequest;
use App\Http\Requests\AccessControl\UpdateRolePermissionsRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->map(fn($p) => [
                'name'  => $p->name,
                'label' => Str::of($p->name)->replace('.', ' ')->title()->toString(),
            ])
            ->values();

        $roles = Role::query()
            ->where('guard_name', self::GUARD)
            ->whereIn('name', self::ALL_ROLES)
            ->with('permissions:id,name')
            ->get()
            ->sortBy(fn($role) => array_search($role->name, self::ALL_ROLES, true))
            ->values()
            ->map(fn($role) => [
                'name'        => $role->name,
                'permissions' => $role->permissions->pluck('name')->sort()->values(),
            ])
            ->values();

        $users = User::query()
            ->with(['stores:store_id,store_name', 'roles:id,name'])
            ->orderByDesc('user_id')
            ->get()
            ->map(function ($user) {
                return [
                    'user_id'   => $user->user_id,
                    'full_name' => $user->full_name
                        ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                        ?: ($user->name ?? $user->email),
                    'email'  => $user->email,
                    'role'   => $user->roles->pluck('name')->first() ?? $user->role ?? null,
                    'stores' => $user->stores->map(fn($s) => [
                        'store_id'   => $s->store_id,
                        'store_name' => $s->store_name,
                    ])->values(),
                ];
            })
            ->values();

        return response()->json([
            'message'     => 'Access control data retrieved successfully.',
            'permissions' => $permissions,
            'roles'       => $roles,
            'users'       => $users,
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
            $role = Role::findByName($roleName, self::GUARD);
            $role->syncPermissions($request->validated()['permissions'] ?? []);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // Revoke tokens for all users with this role so they re-login with fresh permissions
            User::role($roleName)->each(fn($u) => $u->tokens()->delete());

            $role->load('permissions:id,name');

            return response()->json([
                'message' => ucfirst($roleName) . ' permissions updated successfully.',
                'data'    => [
                    'name'        => $role->name,
                    'permissions' => $role->permissions->pluck('name')->sort()->values(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update role permissions.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function assignUserRole(
        AssignUserRoleRequest $request,
        User $user
    ): JsonResponse {
        try {
            $roleName = $request->validated()['role'];
            $role     = Role::findByName($roleName, self::GUARD);

            $user->syncRoles([$role]);

            // Keep role column in sync
            $user->forceFill(['role' => $role->name])->save();

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // Revoke tokens so user re-logins with the new role's permissions
            $user->tokens()->delete();

            return response()->json([
                'message' => 'User role assigned successfully.',
                'data'    => [
                    'user_id' => $user->user_id,
                    'role'    => $role->name,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to assign user role.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getUserPermissions(User $user): JsonResponse
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return response()->json([
            'data' => [
                'user_id'            => $user->user_id,
                'full_name'          => $user->full_name,
                'role'               => $user->role,
                'role_permissions'   => $user->getPermissionsViaRoles()->pluck('name')->sort()->values(),
                'direct_permissions' => $user->getDirectPermissions()->pluck('name')->sort()->values(),
                'all_permissions'    => $user->getAllPermissions()->pluck('name')->sort()->values(),
            ],
        ]);
    }

    public function updateUserPermissions(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'permissions'   => ['required', 'array'],
            'permissions.*' => [
                'string',
                'exists:permissions,name',
            ],
        ]);

        // Only assign permissions the user doesn't already have via their role
        // This prevents direct permissions conflicting with role permissions
        $rolePermissions   = $user->getPermissionsViaRoles()->pluck('name');
        $directPermissions = collect($request->permissions)
            ->diff($rolePermissions)
            ->values()
            ->all();

        $user->syncPermissions($directPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Revoke tokens so user re-logins with updated permissions
        $user->tokens()->delete();

        $user->unsetRelation('roles')->unsetRelation('permissions');

        return response()->json([
            'message' => 'User permissions updated successfully.',
            'data'    => [
                'user_id'            => $user->user_id,
                'full_name'          => $user->full_name,
                'role'               => $user->role,
                'role_permissions'   => $user->getPermissionsViaRoles()->pluck('name')->sort()->values(),
                'direct_permissions' => $user->getDirectPermissions()->pluck('name')->sort()->values(),
                'all_permissions'    => $user->getAllPermissions()->pluck('name')->sort()->values(),
            ],
        ]);
    }
}