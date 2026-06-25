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
            // ── Page visibility (controls nav links & route access) ───
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
            'page.payments',
            'page.reports',
            'page.access_control',
            'page.settings',

            // ── View/read (list + show, enforced by policies) ─────────
            'stores.view',
            'users.view',
            'cashiers.view',
            'categories.view',
            'products.view',
            'inventory.view',
            'customers.view',
            'billings.view',
            'orders.view',
            'payments.view',
            'reports.view',

            // ── Manage (create + update + delete, enforced by policies)
            'stores.manage',
            'users.manage',
            'cashiers.manage',
            'categories.manage',
            'products.manage',
            'inventory.manage',
            'customers.manage',
            'billings.manage',
            'orders.manage',
            'payments.manage',

            // ── Cross-cutting ─────────────────────────────────────────
            'stores.assign',    // link/unlink stores to users
            'users.assign',     // assign roles to users
            'payments.charge',  // process a charge at POS

            // ── POS actions ───────────────────────────────────────────
            'pos.access',
            'pos.draft',
            'pos.void',
            'pos.refund',
            'pos.discount',
            'pos.price_override',
        ];

        // ── Admin: everything ─────────────────────────────────────────
        $adminPermissions = $allPermissions;

        // ── Manager: full ops + catalog, no system pages ──────────────
        $managerPermissions = [
            // Pages
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
            'page.payments',
            'page.reports',

            // View
            'stores.view',
            'users.view',
            'cashiers.view',
            'categories.view',
            'products.view',
            'inventory.view',
            'customers.view',
            'billings.view',
            'orders.view',
            'payments.view',
            'reports.view',

            // Manage
            'stores.assign',
            'users.manage',
            'users.assign',
            'cashiers.manage',
            'categories.manage',
            'products.manage',
            'inventory.manage',
            'customers.manage',
            'billings.manage',
            'orders.manage',
            'payments.manage',
            'payments.charge',

            // POS
            'pos.access',
            'pos.draft',
            'pos.void',
            'pos.refund',
            'pos.discount',
            'pos.price_override',
        ];

        // ── Cashier: counter-only, view-first, limited manage ─────────
        // No page.* by default — cashier nav is driven by pos.access
        // and any extra page.* granted individually via access control.
        $cashierPermissions = [
            // View — needs to read these to do their job at the counter
            'products.view',
            'categories.view',
            'customers.view',
            'orders.view',
            'billings.view',
            'payments.view',

            // Manage — only what a cashier acts on
            'customers.manage',
            'billings.manage',
            'orders.manage',
            'payments.charge',

            // POS
            'pos.access',
            'pos.draft',
        ];

        foreach ($allPermissions as $permission) {
            Permission::findOrCreate($permission, self::GUARD);
        }

        $admin   = Role::findOrCreate(User::ROLE_ADMIN,   self::GUARD);
        $manager = Role::findOrCreate(User::ROLE_MANAGER, self::GUARD);
        $cashier = Role::findOrCreate(User::ROLE_CASHIER, self::GUARD);

        $admin->syncPermissions($adminPermissions);
        $manager->syncPermissions($managerPermissions);
        $cashier->syncPermissions($cashierPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}