<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grns', function (Blueprint $table) {
            if (!Schema::hasColumn('grns', 'release_to_inventory')) {
                $table->boolean('release_to_inventory')->default(true)->after('payment_status');
            }
        });

        if (Schema::hasColumn('grns', 'release_to_inventory')) {
            DB::table('grns')
                ->whereNotNull('stock_applied_at')
                ->update(['release_to_inventory' => true]);

            DB::table('grns')
                ->where('status', 'completed')
                ->whereNull('stock_applied_at')
                ->update(['release_to_inventory' => false]);
        }

        Schema::table('grn_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('grn_payments', 'bank_reference')) {
                $table->string('bank_reference', 120)->nullable()->after('card_holder');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grn_payments', function (Blueprint $table) {
            if (Schema::hasColumn('grn_payments', 'bank_reference')) {
                $table->dropColumn('bank_reference');
            }
        });

        Schema::table('grns', function (Blueprint $table) {
            if (Schema::hasColumn('grns', 'release_to_inventory')) {
                $table->dropColumn('release_to_inventory');
            }
        });
    }
};
