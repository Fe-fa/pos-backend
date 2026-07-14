<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('grn_items', function (Blueprint $table) {
            if (!Schema::hasColumn('grn_items', 'po_item_id')) {
                $table->unsignedBigInteger('po_item_id')->nullable()->after('grn_id');
            }
            if (!Schema::hasColumn('grn_items', 'quantity_expected')) {
                $table->integer('quantity_expected')->default(0)->after('expiry_date');
            }
            if (!Schema::hasColumn('grn_items', 'quantity_accepted')) {
                $table->integer('quantity_accepted')->default(0)->after('qty_received');
            }
            if (!Schema::hasColumn('grn_items', 'quantity_rejected')) {
                $table->integer('quantity_rejected')->default(0)->after('quantity_accepted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grn_items', function (Blueprint $table) {
            foreach (['po_item_id', 'quantity_expected', 'quantity_accepted', 'quantity_rejected'] as $column) {
                if (Schema::hasColumn('grn_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
