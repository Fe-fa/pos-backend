<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE mpesa_transactions
            MODIFY status ENUM('pending', 'sent', 'received', 'success', 'failed', 'cancelled', 'timeout') NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE mpesa_transactions
            MODIFY status ENUM('pending', 'sent', 'success', 'failed', 'cancelled', 'timeout') NOT NULL DEFAULT 'pending'
        ");
    }
};