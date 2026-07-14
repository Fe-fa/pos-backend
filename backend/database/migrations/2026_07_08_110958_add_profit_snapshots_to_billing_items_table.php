<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_items', 'unit_selling_price')) {
                $table->decimal('unit_selling_price', 14, 2)->nullable()->after('unit_price');
            }

            if (!Schema::hasColumn('billing_items', 'unit_cost_price')) {
                $table->decimal('unit_cost_price', 14, 2)->nullable()->after('unit_selling_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_items', function (Blueprint $table) {
            $drops = [];

            if (Schema::hasColumn('billing_items', 'unit_cost_price')) {
                $drops[] = 'unit_cost_price';
            }

            if (Schema::hasColumn('billing_items', 'unit_selling_price')) {
                $drops[] = 'unit_selling_price';
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
