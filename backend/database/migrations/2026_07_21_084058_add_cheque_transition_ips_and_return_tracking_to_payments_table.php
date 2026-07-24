<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'cheque_authorized_ip')) {
                $table->string('cheque_authorized_ip', 45)->nullable()->after('cheque_authorized_by');
            }
            if (!Schema::hasColumn('payments', 'cheque_verified_ip')) {
                $table->string('cheque_verified_ip', 45)->nullable()->after('cheque_verified_by');
            }
            if (!Schema::hasColumn('payments', 'cheque_submitted_ip')) {
                $table->string('cheque_submitted_ip', 45)->nullable()->after('cheque_submitted_by');
            }
            if (!Schema::hasColumn('payments', 'cheque_deposited_ip')) {
                $table->string('cheque_deposited_ip', 45)->nullable()->after('cheque_deposited_by');
            }
            if (!Schema::hasColumn('payments', 'cheque_cleared_ip')) {
                $table->string('cheque_cleared_ip', 45)->nullable()->after('cheque_cleared_by');
            }
            if (!Schema::hasColumn('payments', 'cheque_return_code')) {
                $table->string('cheque_return_code', 60)->nullable()->after('cheque_cleared_ip');
            }
            if (!Schema::hasColumn('payments', 'cheque_return_reason')) {
                $table->string('cheque_return_reason', 500)->nullable()->after('cheque_return_code');
            }
            if (!Schema::hasColumn('payments', 'cheque_returned_at')) {
                $table->timestamp('cheque_returned_at')->nullable()->after('cheque_return_reason');
            }
            if (!Schema::hasColumn('payments', 'cheque_returned_by')) {
                $table->unsignedBigInteger('cheque_returned_by')->nullable()->after('cheque_returned_at');
            }
            if (!Schema::hasColumn('payments', 'cheque_returned_ip')) {
                $table->string('cheque_returned_ip', 45)->nullable()->after('cheque_returned_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            foreach ([
                'cheque_authorized_ip',
                'cheque_verified_ip',
                'cheque_submitted_ip',
                'cheque_deposited_ip',
                'cheque_cleared_ip',
                'cheque_return_code',
                'cheque_return_reason',
                'cheque_returned_at',
                'cheque_returned_by',
                'cheque_returned_ip',
            ] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
