<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->bigIncrements('payment_voucher_id');
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('grn_id');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('prepared_by_user_id');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->string('voucher_number')->nullable()->unique();
            $table->date('voucher_date');
            $table->string('delivery_note_no')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('payee_name');
            $table->text('payee_address')->nullable();
            $table->string('payment_method', 40)->default('cash');
            $table->string('payment_account')->nullable();
            $table->string('cheque_no')->nullable();
            $table->date('cheque_date')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('balance_due', 14, 2)->default(0);
            $table->string('status', 40)->default('approved');
            $table->string('authorized_by')->nullable();
            $table->string('authorized_signature')->nullable();
            $table->date('authorized_date')->nullable();
            $table->json('line_items')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'status']);
            $table->index(['supplier_id', 'voucher_date']);
            $table->index(['grn_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_vouchers');
    }
};
