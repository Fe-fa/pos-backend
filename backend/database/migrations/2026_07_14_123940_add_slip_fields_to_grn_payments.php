<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('grn_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('grn_payments', 'slip_number')) {
                $table->string('slip_number')->nullable()->after('payment_number')->index();
            }

            if (!Schema::hasColumn('grn_payments', 'slip_type')) {
                $table->string('slip_type')->nullable()->after('slip_number');
            }

            if (!Schema::hasColumn('grn_payments', 'slip_generated_at')) {
                $table->timestamp('slip_generated_at')->nullable()->after('slip_type');
            }

            if (!Schema::hasColumn('grn_payments', 'installment_number')) {
                $table->unsignedInteger('installment_number')->nullable()->after('slip_generated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grn_payments', function (Blueprint $table) {
            foreach (['installment_number', 'slip_generated_at', 'slip_type', 'slip_number'] as $column) {
                if (Schema::hasColumn('grn_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
