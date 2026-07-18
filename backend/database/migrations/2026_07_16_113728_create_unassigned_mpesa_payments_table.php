<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unassigned_mpesa_payments', function (Blueprint $table) {
            $table->bigIncrements('unassigned_mpesa_payment_id');
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('mpesa_transaction_id')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('phone_number', 40)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('bill_ref_number')->nullable();
            $table->string('status', 40)->default('UNASSIGNED')->index();
            $table->boolean('conflict_flagged')->default(false)->index();
            $table->json('candidate_billing_ids')->nullable();
            $table->json('candidate_attempt_ids')->nullable();
            $table->string('claimed_terminal_id', 120)->nullable();
            $table->unsignedBigInteger('claimed_billing_id')->nullable()->index();
            $table->unsignedBigInteger('claimed_by_user_id')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unassigned_mpesa_payments');
    }
};
