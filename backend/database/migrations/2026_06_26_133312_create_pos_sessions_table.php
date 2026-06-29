<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('billing_id')->nullable();
            $table->unsignedBigInteger('selected_customer_id')->nullable();

            $table->text('notes')->nullable();
            $table->json('local_items')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'store_id'], 'pos_sessions_user_store_unique');

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('store_id')
                ->references('store_id')
                ->on('stores')
                ->cascadeOnDelete();

            $table->foreign('billing_id')
                ->references('billing_id')
                ->on('billing')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};
