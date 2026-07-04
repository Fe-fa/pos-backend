<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central ledger for every M-Pesa attempt (STK Push, C2B) — kept independent
 * of the `payments` table so we can freely track failed/pending states without
 * dirtying the money-side ledger. When a transaction reaches status=success,
 * a matching row is written into `payments` via PaymentService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_transactions', function (Blueprint $table) {
            $table->bigIncrements('mpesa_transaction_id');
            $table->uuid('uuid')->unique();

            // Which POS entity this attempt is for
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('billing_id')->nullable()->index();
            $table->unsignedBigInteger('grn_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // cashier

            // Channel & mode
            $table->enum('channel', ['stk_push', 'c2b', 'manual'])->index();
            $table->enum('shortcode_type', ['paybill', 'till'])->nullable();

            // Idempotency — one active attempt per billing at a time
            $table->string('idempotency_key', 80)->nullable()->unique();

            // Safaricom identifiers
            $table->string('merchant_request_id', 80)->nullable()->index();
            $table->string('checkout_request_id', 80)->nullable()->unique();
            $table->string('mpesa_receipt', 40)->nullable()->unique(); // e.g. QGH7X8Y2K1
            $table->string('conversation_id', 80)->nullable();
            $table->string('originator_conversation_id', 80)->nullable();

            // Money & payer
            $table->decimal('amount', 15, 2);
            $table->string('phone_number', 20)->nullable(); // 2547XXXXXXXX
            $table->string('account_reference', 60)->nullable(); // e.g. INV-000123
            $table->string('transaction_desc', 200)->nullable();
            $table->timestamp('transaction_date')->nullable();

            // Status machine: pending → sent → success | failed | cancelled | timeout
            $table->enum('status', [
                'pending', 'sent', 'success', 'failed', 'cancelled', 'timeout',
            ])->default('pending')->index();

            $table->string('result_code', 20)->nullable();
            $table->string('result_desc', 255)->nullable();

            // Full raw payloads for audit / debugging (never trust these blindly)
            $table->json('request_payload')->nullable();
            $table->json('callback_payload')->nullable();

            // Environment used for this transaction
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');

            // FK to payments — populated once confirmed
            $table->unsignedBigInteger('payment_id')->nullable()->index();

            $table->timestamps();

            $table->index(['billing_id', 'status']);
            $table->index(['grn_id', 'status']);
            $table->index(['phone_number', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_transactions');
    }
};
