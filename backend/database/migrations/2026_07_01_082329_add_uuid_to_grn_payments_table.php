<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grn_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('grn_payments', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('grn_payment_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grn_payments', function (Blueprint $table) {
            if (Schema::hasColumn('grn_payments', 'uuid')) {
                $table->dropColumn('uuid');
            }
        });
    }
};