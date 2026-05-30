<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
         RolePermissionSeeder::class,
         StoreSeeder::class,
AdminUserSeeder::class,
// MasterSeeder::class,

        ]);
    }
}
