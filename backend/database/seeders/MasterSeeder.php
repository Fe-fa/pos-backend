<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Store;
use App\Models\Category;
use App\Models\User;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Payment;
use App\Models\StockMovement;

class MasterSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::first();

        if (!$store) {
            throw new \RuntimeException('No store found — run StoreSeeder before MasterSeeder.');
        }

        $admin = User::create([
            'first_name'        => 'Festus',
            'last_name'         => 'Admin',
            'username'          => 'admin_user',
            'email'             => 'admin@example.com',
            'phone'             => '0711111111',
            'password'          => bcrypt('password123'),
            'role'              => User::ROLE_ADMIN,
            'default_store_id'  => $store->store_id,
            'is_active'         => true,
            'is_verified'       => true,
            'email_verified_at' => now(),
        ]);
        $admin->assignRole(User::ROLE_ADMIN);
    }
}