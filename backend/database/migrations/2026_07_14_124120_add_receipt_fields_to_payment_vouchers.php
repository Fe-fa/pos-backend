<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_vouchers', 'receipt_number')) {
                $table->string('receipt_number')->nullable()->after('status')->index();
            }

            if (!Schema::hasColumn('payment_vouchers', 'receipt_generated_at')) {
                $table->timestamp('receipt_generated_at')->nullable()->after('receipt_number');
            }

            if (!Schema::hasColumn('payment_vouchers', 'receipt_generated_by_user_id')) {
                $table->unsignedBigInteger('receipt_generated_by_user_id')->nullable()->after('receipt_generated_at');
            }

            if (!Schema::hasColumn('payment_vouchers', 'receipt_payment_breakdown')) {
                $table->json('receipt_payment_breakdown')->nullable()->after('receipt_generated_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            foreach (['receipt_payment_breakdown', 'receipt_generated_by_user_id', 'receipt_generated_at', 'receipt_number'] as $column) {
                if (Schema::hasColumn('payment_vouchers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
