<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grns', function (Blueprint $table) {
            $table->decimal('paid_amount', 12, 2)->default(0)->after('final_total');
            $table->decimal('balance_due', 12, 2)->default(0)->after('paid_amount');
            $table->string('payment_status', 20)->default('unpaid')->after('balance_due');
            $table->timestamp('last_payment_at')->nullable()->after('payment_status');
        });

        Schema::create('grn_payments', function (Blueprint $table) {
            $table->bigIncrements('grn_payment_id');
            $table->unsignedBigInteger('grn_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('payment_number')->nullable()->unique();
            $table->string('payment_method', 20); // cash | mpesa | card
            $table->string('status', 20)->default('posted');

            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_received', 12, 2)->default(0);
            $table->decimal('amount_tendered', 12, 2)->default(0);
            $table->decimal('change_returned', 12, 2)->default(0);

            $table->string('mpesa_phone', 30)->nullable();
            $table->string('mpesa_code', 100)->nullable();
            $table->string('card_reference', 100)->nullable();
            $table->string('card_holder', 150)->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('grn_id')->references('grn_id')->on('grns')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_payments');

        Schema::table('grns', function (Blueprint $table) {
            $table->dropColumn([
                'paid_amount',
                'balance_due',
                'payment_status',
                'last_payment_at',
            ]);
        });
    }
};
