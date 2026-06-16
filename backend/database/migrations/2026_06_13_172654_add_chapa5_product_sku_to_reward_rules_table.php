<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('reward_rules', 'chapa5_product_sku')) {
            Schema::table('reward_rules', function (Blueprint $table) {
                $table->string('chapa5_product_sku', 100)
                    ->nullable()
                    ->after('chapa5_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reward_rules', 'chapa5_product_sku')) {
            Schema::table('reward_rules', function (Blueprint $table) {
                $table->dropColumn('chapa5_product_sku');
            });
        }
    }
};
