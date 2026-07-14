<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('supplier_ledger_entry_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('grn_id')->nullable();
            $table->unsignedBigInteger('grn_payment_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('entry_type', 30);
            $table->string('direction', 10);
            $table->string('reference_number', 100)->nullable();
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->timestamp('entry_date')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
