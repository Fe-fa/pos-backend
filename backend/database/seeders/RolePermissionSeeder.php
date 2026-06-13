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
            // ── Page access ──────────────────────────────────────────
            'page.dashboard',
            'page.stores',
            'page.users',
            'page.cashiers',
            'page.categories',
            'page.products',
            'page.inventory',
            'page.customers',
            'page.billings',
            'page.orders',
            'page.access_control',
            'page.settings',
            'page.pos',
            'page.reports',

            // ── Store & user management ───────────────────────────────
            'stores.manage',
            'stores.assign',
            'users.manage',
            'users.assign',

            // ── Catalog ───────────────────────────────────────────────
            'categories.manage',
            'products.manage',
            'inventory.manage',

            // ── Customers ─────────────────────────────────────────────
            'customers.manage',

            // ── Billing & payments ────────────────────────────────────
            'billings.manage',
            'orders.manage',
            'payments.charge',
            'payments.manage', 

            // ── POS actions ───────────────────────────────────────────
            'pos.access',
            'pos.draft',
            'pos.void',
            'pos.refund',
            'pos.discount',
            'pos.price_override',
        ];

        $managerPermissions = [
            // Pages — managers see everything except access control & settings
            'page.dashboard',
            'page.stores',
            'page.users',
            'page.cashiers',
            'page.categories',
            'page.products',
            'page.inventory',
            'page.customers',
            'page.billings',
            'page.orders',
            'page.pos',
            'page.reports',

            // Actions
            'stores.assign',
            'users.manage',
            'users.assign',
            'categories.manage',
            'products.manage',
            'inventory.manage',
            'customers.manage',
            'billings.manage',
            'orders.manage',
            'payments.charge',
            'pos.access',
            'pos.draft',
            'pos.void',
            'pos.refund',
            'pos.discount',
            'pos.price_override',
        ];

        $cashierPermissions = [
            // Pages — cashiers only see what they need at the counter
            'page.dashboard',
            'page.pos',
            'page.customers',
            'page.orders',
            'page.billings',

            // Actions
            'customers.manage',
            'billings.manage',
            'payments.charge',
            'pos.access',
            'pos.draft',
            'payments.manage',
        ];

        foreach ($allPermissions as $permission) {
            Permission::findOrCreate($permission, self::GUARD);
        }

        $admin   = Role::findOrCreate(User::ROLE_ADMIN,   self::GUARD);
        $manager = Role::findOrCreate(User::ROLE_MANAGER, self::GUARD);
        $cashier = Role::findOrCreate(User::ROLE_CASHIER, self::GUARD);

        $admin->syncPermissions($allPermissions);
        $manager->syncPermissions($managerPermissions);
        $cashier->syncPermissions($cashierPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}