<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('grn_items', function (Blueprint $table) {
            $table->bigIncrements('grn_item_id');
            $table->uuid('uuid')->unique()->nullable();
            $table->unsignedBigInteger('grn_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name_snapshot', 255)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('brand_name', 150)->nullable();
            $table->string('item_type', 50)->nullable();
            $table->string('batch_no', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->integer('qty_received')->default(1);
            $table->integer('free_qty')->default(0);
            $table->decimal('cost_price_excl_tax', 14, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('cess_amount', 14, 2)->default(0);
            $table->string('tax_type', 50)->nullable();
            $table->string('hsn_code', 50)->nullable();
            $table->string('prod_code', 100)->nullable();
            $table->decimal('cost_price_incl_tax', 14, 2)->default(0);
            $table->decimal('mrp', 14, 2)->default(0);
            $table->decimal('selling_price', 14, 2)->default(0);
            $table->decimal('scheme_discount_percent', 8, 2)->default(0);
            $table->decimal('scheme_discount_amount', 14, 2)->default(0);
            $table->decimal('key_discount_percent', 8, 2)->default(0);
            $table->decimal('key_discount_amount', 14, 2)->default(0);
            $table->decimal('cash_discount_amount', 14, 2)->default(0);
            $table->decimal('total_discount_amount', 14, 2)->default(0);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->integer('low_inventory_level')->default(0);
            $table->string('category_name', 120)->nullable();
            $table->string('subcategory_name', 120)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('grn_id')->references('grn_id')->on('grns')->cascadeOnDelete();
            $table->foreign('product_id')->references('product_id')->on('products')->restrictOnDelete();
            $table->index(['grn_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_items');
    }
};
