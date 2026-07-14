<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE mpesa_transactions
            MODIFY channel ENUM('stk_push', 'c2b', 'manual', 'b2c', 'b2b') NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE mpesa_transactions
            MODIFY channel ENUM('stk_push', 'c2b', 'manual') NOT NULL
        ");
    }
};
