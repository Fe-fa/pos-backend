<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_can_login_and_receive_token(): void
    {
        $store = Store::create([
            'store_name' => 'Main Store',
            'location' => 'Nairobi',
            'currency' => 'KES',
            'telephone' => '0700000000',
            'pin' => 'P000000000A',
            'physical_address' => 'CBD',
            'email_address' => 'store@example.com',
            'is_active' => true,
        ]);

        $user = User::create([
            'username' => 'cashier1',
            'full_name' => 'Cashier One',
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

        $response = $this->postJson('/api/auth/login', [
            'username' => 'cashier1',
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'token_type',
                'access_token',
                'user' => [
                    'user_id',
                    'username',
                    'full_name',
                    'role',
                    'is_active',
                    'default_store_id',
                    'default_store',
                    'stores',
                    'roles',
                    'permissions',
                ],
            ]);
    }

    public function test_authenticated_user_can_view_profile_and_logout(): void
    {
        $store = Store::create([
            'store_name' => 'Main Store',
            'location' => 'Nairobi',
            'currency' => 'KES',
            'telephone' => '0700000000',
            'pin' => 'P000000000A',
            'physical_address' => 'CBD',
            'email_address' => 'store@example.com',
            'is_active' => true,
        ]);

        $user = User::create([
            'username' => 'manager1',
            'full_name' => 'Manager One',
            'password_hash' => Hash::make('secret123'),
            'role' => User::ROLE_MANAGER,
            'default_store_id' => $store->store_id,
            'is_active' => true,
        ]);

        $user->syncRoles([User::ROLE_MANAGER]);

        DB::table('user_stores')->insert([
            'user_id' => $user->user_id,
            'store_id' => $store->store_id,
            'assigned_at' => now(),
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'manager1',
            'password' => 'secret123',
            'device_name' => 'phpunit',
        ])->assertOk();

        $token = $loginResponse->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.username', 'manager1');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson([
                'message' => 'Logout successful.',
            ]);
    }
}
