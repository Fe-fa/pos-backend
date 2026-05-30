<?php

namespace Database\Seeders;

use App\Models\DocumentSequence;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $store = Store::firstOrCreate(
                ['store_name' => env('DEFAULT_STORE_NAME', 'Main Store')],
                [
                    'location' => env('DEFAULT_STORE_LOCATION', 'Nairobi'),
                    'currency' => env('DEFAULT_STORE_CURRENCY', 'KES'),
                    'logo_url' => null,
                    'telephone' => env('DEFAULT_STORE_PHONE', '0700000000'),
                    'pin' => env('DEFAULT_STORE_PIN', 'P000000000A'),
                    'physical_address' => env('DEFAULT_STORE_ADDRESS', 'Main Branch'),
                    'email_address' => env('DEFAULT_STORE_EMAIL', 'store@example.com'),
                    'is_active' => true,
                ]
            );

            DocumentSequence::firstOrCreate(
                [
                    'store_id' => $store->store_id,
                    'document_type' => 'Invoice',
                ],
                [
                    'prefix' => 'INV',
                    'suffix' => '',
                    'last_number' => 0,
                ]
            );

            DocumentSequence::firstOrCreate(
                [
                    'store_id' => $store->store_id,
                    'document_type' => 'Receipt',
                ],
                [
                    'prefix' => 'RCT',
                    'suffix' => '',
                    'last_number' => 0,
                ]
            );

$admin = User::updateOrCreate(
    ['username' => env('ADMIN_USERNAME', 'admin')],
    [
        'first_name' => 'System',
        'last_name' => 'Administrator',
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD', 'admin12345'),
        'role' => User::ROLE_ADMIN,
        'default_store_id' => $store->store_id,
        'is_active' => true,
        'is_verified' => true,
        'email_verified_at' => now(),
    ]
);

            DB::table('user_stores')->updateOrInsert(
                [
                    'user_id' => $admin->user_id,
                    'store_id' => $store->store_id,
                ],
                [
                    'assigned_at' => now(),
                ]
            );

            $admin->syncRoles([User::ROLE_ADMIN]);
        });
    }
}
