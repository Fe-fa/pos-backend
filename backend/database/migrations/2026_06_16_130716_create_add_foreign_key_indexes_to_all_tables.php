<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Returns true if the given index already exists on the table.
     * Works for both single-column and composite indexes.
     *
     * @param  string          $table
     * @param  string|string[] $columns  — single column name OR array of column names
     * @return bool
     */
    private function indexExists(string $table, string|array $columns): bool
    {
        $columns  = (array) $columns;
        $indexes  = Schema::getIndexes($table);          // Laravel 10.x+

        foreach ($indexes as $index) {
            // $index['columns'] is an array of column names in definition order
            if ($index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add an index only when it does not already exist.
     */
    private function addIndex(Blueprint $table, string|array $columns): void
    {
        if (! $this->indexExists($table->getTable(), (array) $columns)) {
            $table->index($columns);
        }
    }

    public function up(): void
    {
        // ── users ──────────────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $this->addIndex($table, 'default_store_id');
        });

        // ── user_stores (pivot) ────────────────────────────────────────
        Schema::table('user_stores', function (Blueprint $table) {
            $this->addIndex($table, 'user_id');
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, ['user_id', 'store_id']);
        });

        // ── categories ─────────────────────────────────────────────────
        Schema::table('categories', function (Blueprint $table) {
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, 'category_name');
            $this->addIndex($table, ['store_id', 'category_name']);
        });

        // ── products ───────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, 'category_id');
            $this->addIndex($table, 'sku');
            $this->addIndex($table, 'product_name');
            $this->addIndex($table, ['store_id', 'category_id']);
            $this->addIndex($table, ['store_id', 'is_active']);
            $this->addIndex($table, ['category_id', 'store_id']);
        });

        // ── inventory ──────────────────────────────────────────────────
        Schema::table('inventory', function (Blueprint $table) {
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, 'product_id');
            $this->addIndex($table, 'batch_no');
            $this->addIndex($table, ['store_id', 'product_id']);
            $this->addIndex($table, ['product_id', 'created_at', 'inventory_id']);
        });

        // ── inventory_histories ────────────────────────────────────────
        Schema::table('inventory_histories', function (Blueprint $table) {
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, 'product_id');
            $this->addIndex($table, 'user_id');
            $this->addIndex($table, 'inventory_id');
            $this->addIndex($table, 'change_type');
            $this->addIndex($table, 'batch_no');
            $this->addIndex($table, 'reference');
            $this->addIndex($table, ['store_id', 'product_id']);
            $this->addIndex($table, ['store_id', 'change_type']);
        });

        // ── billing ────────────────────────────────────────────────────
        Schema::table('billing', function (Blueprint $table) {
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, 'customer_id');
            $this->addIndex($table, 'user_id');
            $this->addIndex($table, 'status');
            $this->addIndex($table, 'is_draft');
            $this->addIndex($table, 'fulfillment_status');
            $this->addIndex($table, 'fulfillment_type');
            $this->addIndex($table, 'deleted_at');
            $this->addIndex($table, ['store_id', 'status']);
            $this->addIndex($table, ['store_id', 'is_draft']);
            $this->addIndex($table, ['store_id', 'fulfillment_status']);
            $this->addIndex($table, ['store_id', 'deleted_at']);
        });

        // ── billing_items ──────────────────────────────────────────────
        Schema::table('billing_items', function (Blueprint $table) {
            $this->addIndex($table, 'billing_id');
            $this->addIndex($table, 'product_id');
            $this->addIndex($table, 'deleted_at');
            $this->addIndex($table, ['billing_id', 'product_id']);
        });

        // ── payments ───────────────────────────────────────────────────
        Schema::table('payments', function (Blueprint $table) {
            $this->addIndex($table, 'billing_id');
            $this->addIndex($table, 'payment_date');
            $this->addIndex($table, ['billing_id', 'payment_date']);
        });

        // ── stock_movements ────────────────────────────────────────────
        Schema::table('stock_movements', function (Blueprint $table) {
            $this->addIndex($table, 'product_id');
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, 'user_id');
            $this->addIndex($table, 'type');
            $this->addIndex($table, ['store_id', 'product_id']);
        });

        // ── customers ──────────────────────────────────────────────────
        Schema::table('customers', function (Blueprint $table) {
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, 'email');
            $this->addIndex($table, 'phone');
            $this->addIndex($table, ['store_id', 'email']);
        });

        // ── loyalty_transactions ───────────────────────────────────────
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, 'customer_id');
            $this->addIndex($table, 'billing_id');
            $this->addIndex($table, 'transaction_type');
            $this->addIndex($table, ['store_id', 'customer_id']);
        });

        // ── reward_rules ───────────────────────────────────────────────
        Schema::table('reward_rules', function (Blueprint $table) {
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, 'is_active');
            $this->addIndex($table, ['store_id', 'is_active']);
        });

        // ── document_sequences ─────────────────────────────────────────
        Schema::table('document_sequences', function (Blueprint $table) {
            $this->addIndex($table, 'store_id');
            $this->addIndex($table, ['store_id', 'document_type']);
        });

        // ── sessions ───────────────────────────────────────────────────
        Schema::table('sessions', function (Blueprint $table) {
            $this->addIndex($table, 'user_id');
            $this->addIndex($table, 'last_activity');
        });
    }

    public function down(): void
    {
        // Helper: drop index only if it exists
        $dropIfExists = function (Blueprint $table, array $columns) {
            if ($this->indexExists($table->getTable(), $columns)) {
                $table->dropIndex($columns);
            }
        };

        Schema::table('users', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['default_store_id']);
        });

        Schema::table('user_stores', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['user_id']);
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['user_id', 'store_id']);
        });

        Schema::table('categories', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['category_name']);
            $dropIfExists($table, ['store_id', 'category_name']);
        });

        Schema::table('products', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['category_id']);
            $dropIfExists($table, ['sku']);
            $dropIfExists($table, ['product_name']);
            $dropIfExists($table, ['store_id', 'category_id']);
            $dropIfExists($table, ['store_id', 'is_active']);
            $dropIfExists($table, ['category_id', 'store_id']);
        });

        Schema::table('inventory', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['product_id']);
            $dropIfExists($table, ['batch_no']);
            $dropIfExists($table, ['store_id', 'product_id']);
            $dropIfExists($table, ['product_id', 'created_at', 'inventory_id']);
        });

        Schema::table('inventory_histories', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['product_id']);
            $dropIfExists($table, ['user_id']);
            $dropIfExists($table, ['inventory_id']);
            $dropIfExists($table, ['change_type']);
            $dropIfExists($table, ['batch_no']);
            $dropIfExists($table, ['reference']);
            $dropIfExists($table, ['store_id', 'product_id']);
            $dropIfExists($table, ['store_id', 'change_type']);
        });

        Schema::table('billing', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['customer_id']);
            $dropIfExists($table, ['user_id']);
            $dropIfExists($table, ['status']);
            $dropIfExists($table, ['is_draft']);
            $dropIfExists($table, ['fulfillment_status']);
            $dropIfExists($table, ['fulfillment_type']);
            $dropIfExists($table, ['deleted_at']);
            $dropIfExists($table, ['store_id', 'status']);
            $dropIfExists($table, ['store_id', 'is_draft']);
            $dropIfExists($table, ['store_id', 'fulfillment_status']);
            $dropIfExists($table, ['store_id', 'deleted_at']);
        });

        Schema::table('billing_items', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['billing_id']);
            $dropIfExists($table, ['product_id']);
            $dropIfExists($table, ['deleted_at']);
            $dropIfExists($table, ['billing_id', 'product_id']);
        });

        Schema::table('payments', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['billing_id']);
            $dropIfExists($table, ['payment_date']);
            $dropIfExists($table, ['billing_id', 'payment_date']);
        });

        Schema::table('stock_movements', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['product_id']);
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['user_id']);
            $dropIfExists($table, ['type']);
            $dropIfExists($table, ['store_id', 'product_id']);
        });

        Schema::table('customers', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['email']);
            $dropIfExists($table, ['phone']);
            $dropIfExists($table, ['store_id', 'email']);
        });

        Schema::table('loyalty_transactions', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['customer_id']);
            $dropIfExists($table, ['billing_id']);
            $dropIfExists($table, ['transaction_type']);
            $dropIfExists($table, ['store_id', 'customer_id']);
        });

        Schema::table('reward_rules', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['is_active']);
            $dropIfExists($table, ['store_id', 'is_active']);
        });

        Schema::table('document_sequences', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['store_id']);
            $dropIfExists($table, ['store_id', 'document_type']);
        });

        Schema::table('sessions', function (Blueprint $table) use ($dropIfExists) {
            $dropIfExists($table, ['user_id']);
            $dropIfExists($table, ['last_activity']);
        });
    }
};