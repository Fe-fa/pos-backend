<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Optimize the Products Table
        Schema::table('products', function (Blueprint $table) {
            // Composite index for multi-tenant isolation, state filtering, and ordering
            $table->index(['store_id', 'is_active', 'product_id']);
            
            // Single index for swift SKU/Barcode matches
            $table->index('sku'); 
        });

        // 2. Optimize the Inventory Table (Singular Name Fixed)
        Schema::table('inventory', function (Blueprint $table) {
            // Composite index to dramatically accelerate withCount('inventories') and withSum('inventories', 'quantity')
            $table->index(['product_id', 'quantity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'is_active', 'product_id']);
            $table->dropIndex(['sku']);
        });

        Schema::table('inventory', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'quantity']);
        });
    }
};