<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            if (!Schema::hasColumn('billing', 'fulfillment_status')) {
                $table->string('fulfillment_status', 30)
                    ->default('pending')
                    ->after('status');
            }

            if (!Schema::hasColumn('billing', 'fulfillment_type')) {
                $table->string('fulfillment_type', 30)
                    ->default('walk_in_counter')
                    ->after('fulfillment_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            // Drop columns in reverse order to maintain clean rollback behavior
            if (Schema::hasColumn('billing', 'fulfillment_type')) {
                $table->dropColumn('fulfillment_type');
            }

            if (Schema::hasColumn('billing', 'fulfillment_status')) {
                $table->dropColumn('fulfillment_status');
            }
        });
    }
};