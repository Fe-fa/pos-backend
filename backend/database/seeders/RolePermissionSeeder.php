<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private const GUARD = 'sanctum';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allPermissions = [
            'stores.manage',
            'users.manage',
            'users.assign',
            'categories.manage',
            'customers.manage',
            'products.manage',
            'inventory.manage',
            'billings.manage',
            'orders.manage',
            'payments.charge',
        ];

        $managerPermissions = [
            'users.manage',
            'users.assign',
            'categories.manage',
            'customers.manage',
            'products.manage',
            'inventory.manage',
            'billings.manage',
            'orders.manage',
            'payments.charge',
        ];

        $cashierPermissions = [
            'billings.manage',
            'payments.charge',
        ];

        foreach ($allPermissions as $permission) {
            Permission::findOrCreate($permission, self::GUARD);
        }

        $admin = Role::findOrCreate(User::ROLE_ADMIN, self::GUARD);
        $manager = Role::findOrCreate(User::ROLE_MANAGER, self::GUARD);
        $cashier = Role::findOrCreate(User::ROLE_CASHIER, self::GUARD);

        $admin->syncPermissions($allPermissions);
        $manager->syncPermissions($managerPermissions);
        $cashier->syncPermissions($cashierPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
