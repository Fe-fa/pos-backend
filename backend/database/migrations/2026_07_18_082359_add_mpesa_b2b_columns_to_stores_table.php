<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (!Schema::hasColumn('stores', 'mpesa_initiator_name')) {
                $table->text('mpesa_initiator_name')->nullable()->after('mpesa_callback_base_url');
            }
            if (!Schema::hasColumn('stores', 'mpesa_initiator_password')) {
                $table->text('mpesa_initiator_password')->nullable()->after('mpesa_initiator_name');
            }
            if (!Schema::hasColumn('stores', 'mpesa_security_credential')) {
                $table->text('mpesa_security_credential')->nullable()->after('mpesa_initiator_password');
            }
            if (!Schema::hasColumn('stores', 'mpesa_b2b_shortcode')) {
                $table->string('mpesa_b2b_shortcode', 20)->nullable()->after('mpesa_security_credential');
            }
            if (!Schema::hasColumn('stores', 'mpesa_float_balance')) {
                $table->decimal('mpesa_float_balance', 14, 2)->nullable()->after('mpesa_b2b_shortcode');
            }
            if (!Schema::hasColumn('stores', 'mpesa_utility_float_balance')) {
                $table->decimal('mpesa_utility_float_balance', 14, 2)->nullable()->after('mpesa_float_balance');
            }
            if (!Schema::hasColumn('stores', 'mpesa_available_float')) {
                $table->decimal('mpesa_available_float', 14, 2)->nullable()->after('mpesa_utility_float_balance');
            }
            if (!Schema::hasColumn('stores', 'mpesa_last_balance_synced_at')) {
                $table->timestamp('mpesa_last_balance_synced_at')->nullable()->after('mpesa_available_float');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'mpesa_initiator_name',
                'mpesa_initiator_password',
                'mpesa_security_credential',
                'mpesa_b2b_shortcode',
                'mpesa_float_balance',
                'mpesa_utility_float_balance',
                'mpesa_available_float',
                'mpesa_last_balance_synced_at',
            ]);
        });
    }
};