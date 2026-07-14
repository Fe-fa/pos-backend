<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_voucher_id')->nullable()->after('grn_id');
            $table->string('vendor_shortcode', 30)->nullable()->after('phone_number');
            $table->string('receiver_type', 30)->nullable()->after('shortcode_type');

            $table->index('payment_voucher_id');
            $table->index('vendor_shortcode');
            $table->index('receiver_type');
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $table) {
            $table->dropIndex(['payment_voucher_id']);
            $table->dropIndex(['vendor_shortcode']);
            $table->dropIndex(['receiver_type']);
            $table->dropColumn(['payment_voucher_id', 'vendor_shortcode', 'receiver_type']);
        });
    }
};
