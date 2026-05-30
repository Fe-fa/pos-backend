<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('customer_id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('store_id');
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['store_id', 'full_name']);

            $table->foreign('store_id')
                ->references('store_id')
                ->on('stores')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
