<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Avoid duplicate entries if the seeder is run multiple times
        if (User::where('username', 'admin')->exists()) {
            return;
        }

        User::create([
            'first_name'         => 'System',
            'last_name'          => 'Administrator',
            'username'           => 'admin',
            'email'              => 'admin@unity.com',
            'phone'              => '1234567890',
            'password'           => Hash::make('password123'), // Change this in production!
            'role'               => 'admin', // Matches the custom column in your schema
'default_store_id' => \App\Models\Store::first()?->store_id,
            'verification_code'  => null,
            'verification_expiry'=> null,
            'is_active'          => true,
            'is_verified'        => true,    // Setting admin as already verified
            'email_verified_at'  => now(),
        ]);
    }
}