<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnsureStoreAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_with_store_assignment_can_access_store_route(): void
    {
        $store = Store::create([
            'store_name' => 'Assigned Store',
            'location' => 'Nairobi',
            'currency' => 'KES',
            'telephone' => '0700000000',
            'pin' => 'P000000000A',
            'physical_address' => 'Town',
            'email_address' => 'assigned@example.com',
            'is_active' => true,
        ]);

        $user = User::create([
            'username' => 'cashier2',
            'full_name' => 'Cashier Two',
            'password_hash' => Hash::make('secret123'),
            'role' => User::ROLE_CASHIER,
            'default_store_id' => $store->store_id,
            'is_active' => true,
        ]);

        $user->syncRoles([User::ROLE_CASHIER]);

        DB::table('user_stores')->insert([
            'user_id' => $user->user_id,
            'store_id' => $store->store_id,
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$store->store_id}/access-check")
            ->assertOk()
            ->assertJson([
                'message' => 'Store access granted.',
                'store_id' => $store->store_id,
            ]);
    }

    public function test_user_without_store_assignment_is_denied(): void
    {
        $allowedStore = Store::create([
            'store_name' => 'Allowed Store',
            'location' => 'Nairobi',
            'currency' => 'KES',
            'telephone' => '0700000000',
            'pin' => 'P000000000A',
            'physical_address' => 'Town',
            'email_address' => 'allowed@example.com',
            'is_active' => true,
        ]);

        $blockedStore = Store::create([
            'store_name' => 'Blocked Store',
            'location' => 'Mombasa',
            'currency' => 'KES',
            'telephone' => '0711111111',
            'pin' => 'P111111111A',
            'physical_address' => 'Town',
            'email_address' => 'blocked@example.com',
            'is_active' => true,
        ]);

        $user = User::create([
            'username' => 'cashier3',
            'full_name' => 'Cashier Three',
            'password_hash' => Hash::make('secret123'),
            'role' => User::ROLE_CASHIER,
            'default_store_id' => $allowedStore->store_id,
            'is_active' => true,
        ]);

        $user->syncRoles([User::ROLE_CASHIER]);

        DB::table('user_stores')->insert([
            'user_id' => $user->user_id,
            'store_id' => $allowedStore->store_id,
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/stores/{$blockedStore->store_id}/access-check")
            ->assertForbidden()
            ->assertJson([
                'message' => 'You do not have access to this store.',
            ]);
    }
}
