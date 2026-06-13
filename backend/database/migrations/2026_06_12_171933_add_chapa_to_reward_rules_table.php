<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->integer('punch_card_count')->default(0)->after('total_earned_points');
            $table->integer('total_free_items_earned')->default(0)->after('punch_card_count');
        });

        Schema::table('reward_rules', function (Blueprint $table) {
            // Chapa 5 settings
            $table->boolean('chapa5_enabled')->default(false)->after('end_date');
            $table->integer('chapa5_buy_count')->default(5)->after('chapa5_enabled');     // buy X
            $table->integer('chapa5_free_count')->default(1)->after('chapa5_buy_count');  // get Y free
            $table->string('chapa5_label')->default('Chapa 5')->after('chapa5_free_count');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['punch_card_count', 'total_free_items_earned']);
        });

        Schema::table('reward_rules', function (Blueprint $table) {
            $table->dropColumn(['chapa5_enabled', 'chapa5_buy_count', 'chapa5_free_count', 'chapa5_label']);
        });
    }
};