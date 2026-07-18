<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_payment_attempts', function (Blueprint $table) {
            $table->bigIncrements('active_payment_attempt_id');
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('billing_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('terminal_id', 120)->index();
            $table->decimal('expected_amount', 12, 2);
            $table->string('status', 40)->default('WAITING_FOR_PAYMENT')->index();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('split_allocations')->nullable();
            $table->unsignedInteger('points_redeemed')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_payment_attempts');
    }
};
