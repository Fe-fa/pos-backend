<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds M-Pesa specific columns to the existing `payments` table.
 * Non-breaking: all columns are nullable and default null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('mpesa_receipt', 40)->nullable()->after('payment_method');
            $table->string('mpesa_phone', 20)->nullable()->after('mpesa_receipt');
            $table->unsignedBigInteger('mpesa_transaction_id')->nullable()->after('mpesa_phone');
            $table->string('card_reference', 100)->nullable()->after('mpesa_transaction_id');
            $table->string('card_holder', 100)->nullable()->after('card_reference');

            $table->unique('mpesa_receipt', 'payments_mpesa_receipt_unique');
            $table->index('mpesa_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_mpesa_receipt_unique');
            $table->dropIndex(['mpesa_transaction_id']);
            $table->dropColumn([
                'mpesa_receipt',
                'mpesa_phone',
                'mpesa_transaction_id',
                'card_reference',
                'card_holder',
            ]);
        });
    }
};
