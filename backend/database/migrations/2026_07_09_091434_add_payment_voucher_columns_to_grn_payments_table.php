<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grn_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_voucher_id')->nullable()->after('user_id');
            $table->string('payment_voucher_number')->nullable()->after('payment_number');
            $table->index(['payment_voucher_id']);
        });
    }

    public function down(): void
    {
        Schema::table('grn_payments', function (Blueprint $table) {
            $table->dropColumn(['payment_voucher_id', 'payment_voucher_number']);
        });
    }
};
