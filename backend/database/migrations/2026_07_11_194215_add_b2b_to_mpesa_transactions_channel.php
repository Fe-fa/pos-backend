<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen mpesa_transactions.channel to include 'b2b' (supplier settlement).
 * Original enum only had ['stk_push', 'c2b', 'manual'].
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE mpesa_transactions
             MODIFY channel ENUM('stk_push', 'c2b', 'manual', 'b2b') NOT NULL"
        );
    }

    public function down(): void
    {
        // Note: rolling back while any row has channel='b2b' will fail —
        // clean up/reassign those rows first if you ever need to downgrade.
        DB::statement(
            "ALTER TABLE mpesa_transactions
             MODIFY channel ENUM('stk_push', 'c2b', 'manual') NOT NULL"
        );
    }
};