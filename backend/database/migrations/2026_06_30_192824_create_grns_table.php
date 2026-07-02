<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('grns', function (Blueprint $table) {
            $table->bigIncrements('grn_id');
            $table->uuid('uuid')->unique()->nullable();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('grn_number', 50)->nullable()->unique();
            $table->string('invoice_number', 100)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('grn_date');
            $table->string('supplier_name', 255)->nullable();
            $table->boolean('is_po_available')->default(false);
            $table->string('po_number', 100)->nullable();
            $table->string('status', 30)->default('draft');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('additional_discount_1', 14, 2)->default(0);
            $table->decimal('additional_discount_2', 14, 2)->default(0);
            $table->decimal('other_charges', 14, 2)->default(0);
            $table->decimal('round_off', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('final_total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('stock_applied_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('store_id')->references('store_id')->on('stores')->cascadeOnDelete();
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('supplier_id')->references('supplier_id')->on('suppliers')->nullOnDelete();
            $table->index(['store_id', 'status', 'grn_date']);
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grns');
    }
};
