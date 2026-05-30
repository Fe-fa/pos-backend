<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('categories')->insert([
            ['category_name' => 'Coffee', 'created_at' => now(), 'updated_at' => now()],
            ['category_name' => 'Pastries', 'created_at' => now(), 'updated_at' => now()],
            ['category_name' => 'Cold Drinks', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}