<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (!Schema::hasColumn('suppliers', 'credit_days')) {
                $table->unsignedInteger('credit_days')->default(30)->after('opening_balance');
            }
            if (!Schema::hasColumn('suppliers', 'current_balance')) {
                $table->decimal('current_balance', 14, 2)->default(0)->after('credit_days');
            }
            if (!Schema::hasColumn('suppliers', 'outstanding_balance')) {
                $table->decimal('outstanding_balance', 14, 2)->default(0)->after('current_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            foreach (['credit_days', 'current_balance', 'outstanding_balance'] as $column) {
                if (Schema::hasColumn('suppliers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
