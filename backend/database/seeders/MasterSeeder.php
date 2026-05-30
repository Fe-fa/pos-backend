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
        // 1. Seed Store
        $store = Store::create([
            'store_name'    => 'Main Branch',
            'location'      => 'Narok',
            'currency'      => 'KES',
            'telephone'     => '0712345678',
            'email_address' => 'main@store.com',
            'is_active'     => true,
        ]);

        // 2. Seed Category
        $category = Category::create([
            'category_name' => 'Beverages',
        ]);

        // 3. Seed Admin User
        $admin = User::create([
            'first_name'       => 'Festus',
            'last_name'        => 'Admin',
            'username'         => 'admin_user',
            'email'            => 'admin@example.com',
            'phone'            => '0711111111',
            'password'         => bcrypt('password123'),
            'role'             => User::ROLE_ADMIN,
            'default_store_id' => $store->store_id,
            'is_active'        => true,
            'is_verified'      => true,
            'email_verified_at'=> now(),
        ]);
        $admin->assignRole(User::ROLE_ADMIN);

        // 4. Seed Manager User
        $manager = User::create([
            'first_name'       => 'Jane',
            'last_name'        => 'Manager',
            'username'         => 'manager_user',
            'email'            => 'manager@example.com',
            'phone'            => '0722222222',
            'password'         => bcrypt('password123'),
            'role'             => User::ROLE_MANAGER,
            'default_store_id' => $store->store_id,
            'is_active'        => true,
            'is_verified'      => true,
            'email_verified_at'=> now(),
        ]);
        $manager->assignRole(User::ROLE_MANAGER);

        // 5. Seed Cashier User
        $cashier = User::create([
            'first_name'       => 'Paul',
            'last_name'        => 'Cashier',
            'username'         => 'cashier_user',
            'email'            => 'cashier@example.com',
            'phone'            => '0733333333',
            'password'         => bcrypt('password123'),
            'role'             => User::ROLE_CASHIER,
            'default_store_id' => $store->store_id,
            'is_active'        => true,
            'is_verified'      => true,
            'email_verified_at'=> now(),
        ]);
        $cashier->assignRole(User::ROLE_CASHIER);

        // 6. Seed Customer
        $customer = Customer::create([
            'full_name'       => 'John Doe',
            'phone'           => '0744444444',
            'current_balance' => 0,
        ]);

        // 7. Seed Product
        $product = Product::create([
            'category_id'  => $category->category_id,
            'sku'          => 'BEV001',
            'product_name' => 'Mineral Water',
            'price'        => 50,
            'cost_price'   => 30,
            'is_active'    => true,
        ]);

        // 8. Seed Inventory
        Inventory::create([
            'store_id'      => $store->store_id,
            'product_id'    => $product->product_id,
            'quantity'      => 100,
            'reorder_level' => 10,
        ]);

        // 9. Seed Billing
        $billing = Billing::create([
            'store_id'           => $store->store_id,
            'customer_id'        => $customer->customer_id,
            'user_id'            => $admin->user_id,
            'invnumber'          => 'INV-001',
            'total_bill_amount'  => 58,
            'amount_settled'     => 58,
            'status'             => 'paid',
            'billing_date'       => now(),
        ]);

        // 10. Seed Billing Item
        BillingItem::create([
            'billing_id' => $billing->billing_id,
            'product_id' => $product->product_id,
            'quantity'   => 1,
            'unit_price' => 50,
            'amount'     => 50,
        ]);

        // 11. Seed Payment
        Payment::create([
            'billing_id'     => $billing->billing_id,
            'receiptnumber'  => 'RCPT-001',
            'amount_received'=> 58,
            'balance'        => 0,
            'total_balance'  => 0,
            'payment_date'   => now(),
        ]);

        // 12. Seed Stock Movement
        StockMovement::create([
            'product_id' => $product->product_id,
            'store_id'   => $store->store_id,
            'quantity'   => -1,
            'type'       => 'sale',
            'reason'     => 'Customer purchase',
            'user_id'    => $admin->user_id,
        ]);
    }
}
