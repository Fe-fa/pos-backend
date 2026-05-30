<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_histories', function (Blueprint $table) {
            $table->bigIncrements('inventory_history_id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('inventory_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('product_id');
            $table->string('batch_no')->nullable();
            $table->integer('quantity_before');
            $table->integer('quantity_changed');
            $table->integer('quantity_after');
            $table->string('change_type');
            $table->string('reference')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['inventory_id', 'change_type']);
            $table->index(['store_id', 'product_id']);

            $table->foreign('inventory_id')
                ->references('inventory_id')
                ->on('inventory')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('store_id')
                ->references('store_id')
                ->on('stores')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_id')
                ->references('product_id')
                ->on('products')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_histories');
    }
};
