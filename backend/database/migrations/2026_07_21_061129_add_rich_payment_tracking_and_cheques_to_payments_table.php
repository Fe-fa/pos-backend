<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'status')) {
                $table->string('status')->default('paid')->after('payment_method');
            }
            if (!Schema::hasColumn('payments', 'payment_reference')) {
                $table->string('payment_reference')->nullable()->after('receiptnumber');
            }
            if (!Schema::hasColumn('payments', 'mpesa_phone')) {
                $table->string('mpesa_phone', 20)->nullable()->after('change_returned');
            }
            if (!Schema::hasColumn('payments', 'mpesa_receipt')) {
                $table->string('mpesa_receipt', 50)->nullable()->after('mpesa_phone');
            }
            if (!Schema::hasColumn('payments', 'mpesa_mode')) {
                $table->string('mpesa_mode', 20)->nullable()->after('mpesa_receipt');
            }
            if (!Schema::hasColumn('payments', 'card_reference')) {
                $table->string('card_reference', 100)->nullable()->after('mpesa_mode');
            }
            if (!Schema::hasColumn('payments', 'card_holder')) {
                $table->string('card_holder', 100)->nullable()->after('card_reference');
            }
            if (!Schema::hasColumn('payments', 'cheque_bank_name')) {
                $table->string('cheque_bank_name', 120)->nullable()->after('card_holder');
            }
            if (!Schema::hasColumn('payments', 'cheque_bank_code')) {
                $table->string('cheque_bank_code', 30)->nullable()->after('cheque_bank_name');
            }
            if (!Schema::hasColumn('payments', 'cheque_number')) {
                $table->string('cheque_number', 50)->nullable()->after('cheque_bank_code');
            }
            if (!Schema::hasColumn('payments', 'cheque_date')) {
                $table->date('cheque_date')->nullable()->after('cheque_number');
            }
            if (!Schema::hasColumn('payments', 'cheque_account_name')) {
                $table->string('cheque_account_name', 120)->nullable()->after('cheque_date');
            }
            if (!Schema::hasColumn('payments', 'cheque_account_number')) {
                $table->string('cheque_account_number', 50)->nullable()->after('cheque_account_name');
            }
            if (!Schema::hasColumn('payments', 'cheque_branch_name')) {
                $table->string('cheque_branch_name', 120)->nullable()->after('cheque_account_number');
            }
            if (!Schema::hasColumn('payments', 'cheque_status')) {
                $table->string('cheque_status', 40)->nullable()->after('cheque_branch_name');
            }
            if (!Schema::hasColumn('payments', 'cheque_notes')) {
                $table->text('cheque_notes')->nullable()->after('cheque_status');
            }
            if (!Schema::hasColumn('payments', 'cheque_authorized_at')) {
                $table->timestamp('cheque_authorized_at')->nullable()->after('cheque_notes');
            }
            if (!Schema::hasColumn('payments', 'cheque_authorized_by')) {
                $table->unsignedBigInteger('cheque_authorized_by')->nullable()->after('cheque_authorized_at');
            }
            if (!Schema::hasColumn('payments', 'cheque_verified_at')) {
                $table->timestamp('cheque_verified_at')->nullable()->after('cheque_authorized_by');
            }
            if (!Schema::hasColumn('payments', 'cheque_verified_by')) {
                $table->unsignedBigInteger('cheque_verified_by')->nullable()->after('cheque_verified_at');
            }
            if (!Schema::hasColumn('payments', 'cheque_submitted_at')) {
                $table->timestamp('cheque_submitted_at')->nullable()->after('cheque_verified_by');
            }
            if (!Schema::hasColumn('payments', 'cheque_submitted_by')) {
                $table->unsignedBigInteger('cheque_submitted_by')->nullable()->after('cheque_submitted_at');
            }
            if (!Schema::hasColumn('payments', 'cheque_deposited_at')) {
                $table->timestamp('cheque_deposited_at')->nullable()->after('cheque_submitted_by');
            }
            if (!Schema::hasColumn('payments', 'cheque_deposited_by')) {
                $table->unsignedBigInteger('cheque_deposited_by')->nullable()->after('cheque_deposited_at');
            }
            if (!Schema::hasColumn('payments', 'cheque_deposit_reference')) {
                $table->string('cheque_deposit_reference', 120)->nullable()->after('cheque_deposited_by');
            }
            if (!Schema::hasColumn('payments', 'cheque_cleared_at')) {
                $table->timestamp('cheque_cleared_at')->nullable()->after('cheque_deposit_reference');
            }
            if (!Schema::hasColumn('payments', 'cheque_cleared_by')) {
                $table->unsignedBigInteger('cheque_cleared_by')->nullable()->after('cheque_cleared_at');
            }
            if (!Schema::hasColumn('payments', 'cheque_clearing_reference')) {
                $table->string('cheque_clearing_reference', 120)->nullable()->after('cheque_cleared_by');
            }
            if (!Schema::hasColumn('payments', 'payment_meta')) {
                $table->json('payment_meta')->nullable()->after('cheque_clearing_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $columns = [
                'payment_reference',
                'mpesa_phone',
                'mpesa_receipt',
                'mpesa_mode',
                'card_reference',
                'card_holder',
                'cheque_bank_name',
                'cheque_bank_code',
                'cheque_number',
                'cheque_date',
                'cheque_account_name',
                'cheque_account_number',
                'cheque_branch_name',
                'cheque_status',
                'cheque_notes',
                'cheque_authorized_at',
                'cheque_authorized_by',
                'cheque_verified_at',
                'cheque_verified_by',
                'cheque_submitted_at',
                'cheque_submitted_by',
                'cheque_deposited_at',
                'cheque_deposited_by',
                'cheque_deposit_reference',
                'cheque_cleared_at',
                'cheque_cleared_by',
                'cheque_clearing_reference',
                'payment_meta',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
