<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent migration to add supplier_id to an existing `grns` table.
 *
 * If you are starting fresh, the supplier_id column is already part of the
 * create_grns_table migration and this migration becomes a no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('grns')) {
            return;
        }

        Schema::table('grns', function (Blueprint $table) {
            if (!Schema::hasColumn('grns', 'supplier_id')) {
                $table->unsignedBigInteger('supplier_id')->nullable()->after('user_id');
                $table->foreign('supplier_id')
                    ->references('supplier_id')
                    ->on('suppliers')
                    ->nullOnDelete();
                $table->index('supplier_id');
            }
        });

        // Make legacy supplier_name nullable so new rows can rely on supplier_id only.
        if (Schema::hasColumn('grns', 'supplier_name')) {
            try {
                DB::statement("ALTER TABLE grns MODIFY supplier_name VARCHAR(255) NULL");
            } catch (\Throwable $e) {
                // Silently ignore on databases that don't support MODIFY (e.g. SQLite).
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('grns') || !Schema::hasColumn('grns', 'supplier_id')) {
            return;
        }

        Schema::table('grns', function (Blueprint $table) {
            try {
                $table->dropForeign(['supplier_id']);
            } catch (\Throwable $e) {
                // ignore if not present
            }
            try {
                $table->dropIndex(['supplier_id']);
            } catch (\Throwable $e) {
                // ignore if not present
            }
            $table->dropColumn('supplier_id');
        });
    }
};
