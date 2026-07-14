<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->bigIncrements('purchase_order_id');
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('po_number', 50)->nullable()->unique();
            $table->string('status', 30)->default('draft');
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('final_total', 14, 2)->default(0);
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
