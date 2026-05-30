<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->bigIncrements('payment_id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('billing_id');
            $table->string('receiptnumber')->nullable()->unique();
            $table->string('payment_method');
            $table->decimal('amount_received', 15, 2)->default(0);
            $table->decimal('amount_tendered', 15, 2)->default(0);
            $table->decimal('change_returned', 15, 2)->default(0);
            $table->decimal('balance_before', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->timestamp('payment_date')->nullable();
            $table->timestamps();

            $table->index(['billing_id', 'payment_date']);

            $table->foreign('billing_id')
                ->references('billing_id')
                ->on('billing')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
