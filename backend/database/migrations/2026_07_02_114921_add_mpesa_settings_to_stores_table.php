<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-store M-Pesa credentials. .env values act as defaults; any column
 * left null on the store row falls back to config('mpesa.*').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('mpesa_enabled')->default(false)->after('currency');
            $table->enum('mpesa_environment', ['sandbox', 'production'])->nullable()->after('mpesa_enabled');
            $table->enum('mpesa_shortcode_type', ['paybill', 'till'])->nullable()->after('mpesa_environment');
            $table->string('mpesa_shortcode', 20)->nullable()->after('mpesa_shortcode_type');
            $table->string('mpesa_till_number', 20)->nullable()->after('mpesa_shortcode');
            $table->text('mpesa_consumer_key')->nullable()->after('mpesa_till_number');       // encrypted
            $table->text('mpesa_consumer_secret')->nullable()->after('mpesa_consumer_key');   // encrypted
            $table->text('mpesa_passkey')->nullable()->after('mpesa_consumer_secret');        // encrypted
            $table->string('mpesa_callback_base_url', 255)->nullable()->after('mpesa_passkey');
            $table->string('mpesa_account_reference_prefix', 20)->nullable()->after('mpesa_callback_base_url');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'mpesa_enabled',
                'mpesa_environment',
                'mpesa_shortcode_type',
                'mpesa_shortcode',
                'mpesa_till_number',
                'mpesa_consumer_key',
                'mpesa_consumer_secret',
                'mpesa_passkey',
                'mpesa_callback_base_url',
                'mpesa_account_reference_prefix',
            ]);
        });
    }
};
