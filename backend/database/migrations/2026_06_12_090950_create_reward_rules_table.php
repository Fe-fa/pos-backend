// database/migrations/xxxx_create_reward_rules_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->integer('loyalty_points')->default(0)->after('current_balance');
            $table->integer('total_earned_points')->default(0)->after('loyalty_points');
        });

        Schema::create('reward_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('store_id');
            $table->string('rule_name');
            $table->decimal('points_per_shilling', 10, 4)->default(0.01);
            $table->decimal('min_spend_required', 10, 2)->default(0.00);
            $table->decimal('point_value', 10, 4)->default(1.00); // 1 point = 1 KES
            $table->decimal('min_redemption_points', 10, 2)->default(10.00);
            $table->boolean('is_active')->default(true);
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->timestamps();

            $table->foreign('store_id')
                ->references('store_id')
                ->on('stores')
                ->cascadeOnDelete();
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('billing_id')->nullable();
            $table->enum('transaction_type', ['earned', 'redeemed', 'expired', 'refund_deduction']);
            $table->integer('points')->default(0);
            $table->decimal('amount_equivalent', 10, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->foreign('store_id')
                ->references('store_id')
                ->on('stores')
                ->cascadeOnDelete();

            $table->foreign('customer_id')
                ->references('customer_id')
                ->on('customers')
                ->cascadeOnDelete();

            $table->foreign('billing_id')
                ->references('billing_id')
                ->on('billing')
                ->nullOnDelete();

            $table->index(['store_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('reward_rules');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['loyalty_points', 'total_earned_points']);
        });
    }
};