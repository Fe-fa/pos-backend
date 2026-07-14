<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->bigIncrements('purchase_order_item_id');
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name_snapshot', 255)->nullable();
            $table->string('sku_snapshot', 100)->nullable();
            $table->integer('quantity_ordered')->default(1);
            $table->integer('quantity_received')->default(0);
            $table->integer('quantity_rejected_total')->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
