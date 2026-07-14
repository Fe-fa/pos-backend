<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('prepared_by_user_id')->nullable()->change();
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('prepared_by_user_id')->nullable(false)->change();
            $table->unsignedBigInteger('approved_by_user_id')->nullable(false)->change();
        });
    }
};