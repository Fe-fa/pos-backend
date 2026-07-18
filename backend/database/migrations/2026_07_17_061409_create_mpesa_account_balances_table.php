<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_account_balances', function (Blueprint $table) {
            $table->id('mpesa_account_balance_id');
            $table->uuid('uuid')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('shortcode', 20)->nullable()->index();
            $table->string('identifier_type', 10)->default('4');
            $table->string('preferred_account_type', 30)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->decimal('available_balance', 18, 2)->nullable();
            $table->decimal('working_balance', 18, 2)->nullable();
            $table->decimal('utility_balance', 18, 2)->nullable();
            $table->text('raw_balance_text')->nullable();
            $table->string('originator_conversation_id', 120)->nullable()->index();
            $table->string('conversation_id', 120)->nullable()->index();
            $table->string('status', 40)->default('pending')->index();
            $table->string('result_code', 40)->nullable();
            $table->string('result_desc', 255)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('callback_payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_account_balances');
    }
};
