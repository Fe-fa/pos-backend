<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('mpesa_transactions', 'mpesa_receipt')) {
                $table->string('mpesa_receipt')->nullable()->after('idempotency_key');
            }

            if (!Schema::hasColumn('mpesa_transactions', 'request_payload')) {
                $table->json('request_payload')->nullable();
            }

            if (!Schema::hasColumn('mpesa_transactions', 'callback_payload')) {
                $table->json('callback_payload')->nullable();
            }

            if (!Schema::hasColumn('mpesa_transactions', 'payment_id')) {
                $table->unsignedBigInteger('payment_id')->nullable()->after('billing_id');
            }
        });

        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->unique('mpesa_receipt', 'mpesa_transactions_receipt_unique');
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropUnique('mpesa_transactions_receipt_unique');
        });
    }
};
