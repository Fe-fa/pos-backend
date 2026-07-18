<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $t) {
            if (!Schema::hasColumn('mpesa_transactions', 'is_used')) {
                $t->boolean('is_used')->default(false)->after('payment_id');
            }
            if (!Schema::hasColumn('mpesa_transactions', 'is_used_at')) {
                $t->timestamp('is_used_at')->nullable()->after('is_used');
            }
            if (!Schema::hasColumn('mpesa_transactions', 'is_used_by')) {
                $t->unsignedBigInteger('is_used_by')->nullable()->after('is_used_at');
            }
            if (!Schema::hasColumn('mpesa_transactions', 'sms_sent_at')) {
                $t->timestamp('sms_sent_at')->nullable()->after('is_used_by');
            }

            $t->index(['mpesa_receipt', 'status'], 'idx_mpesa_receipt_status');
            $t->index(['mpesa_receipt', 'amount'], 'idx_mpesa_receipt_amount');
            $t->index(['is_used', 'created_at'], 'idx_mpesa_is_used_created');
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_transactions', function (Blueprint $t) {
            $t->dropIndex('idx_mpesa_receipt_status');
            $t->dropIndex('idx_mpesa_receipt_amount');
            $t->dropIndex('idx_mpesa_is_used_created');
            $t->dropColumn(['is_used', 'is_used_at', 'is_used_by', 'sms_sent_at']);
        });
    }
};
