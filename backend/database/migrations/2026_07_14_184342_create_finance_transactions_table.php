<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->bigIncrements('finance_transaction_id');
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('cashier_shift_id')->nullable();
            $table->string('transaction_type', 40); // manual_expense | cash_drop | manual_inflow
            $table->string('flow', 20); // inflow | outgoing
            $table->string('method', 20)->default('cash'); // cash | mpesa | loyalty | system
            $table->string('category', 120);
            $table->string('entity_name', 180);
            $table->string('reference_no', 100)->nullable();
            $table->decimal('amount', 14, 2);
            $table->timestamp('transaction_date');
            $table->string('status', 40)->default('posted');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'transaction_date'], 'fin_tx_store_date_idx');
            $table->index(['store_id', 'flow', 'method'], 'fin_tx_store_flow_method_idx');
            $table->index(['cashier_shift_id', 'transaction_date'], 'fin_tx_shift_date_idx');
            $table->index(['transaction_type', 'category'], 'fin_tx_type_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
    }
};
