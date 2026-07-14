<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('grns', function (Blueprint $table) {
            if (!Schema::hasColumn('grns', 'purchase_order_id')) {
                $table->unsignedBigInteger('purchase_order_id')->nullable()->after('supplier_id');
            }
            if (!Schema::hasColumn('grns', 'po_reconciled_at')) {
                $table->timestamp('po_reconciled_at')->nullable()->after('stock_applied_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grns', function (Blueprint $table) {
            foreach (['purchase_order_id', 'po_reconciled_at'] as $column) {
                if (Schema::hasColumn('grns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
