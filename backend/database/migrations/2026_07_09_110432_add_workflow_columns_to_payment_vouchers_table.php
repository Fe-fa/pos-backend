<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::table('payment_vouchers', function (Blueprint $table) {
        if (!Schema::hasColumn('payment_vouchers', 'due_date')) {
            $table->date('due_date')->nullable()->after('voucher_date');
        }

        if (!Schema::hasColumn('payment_vouchers', 'status')) {
            $table->string('status', 40)->default('draft')->after('amount');
        }

        if (!Schema::hasColumn('payment_vouchers', 'voucher_number')) {
            $table->string('voucher_number', 60)->nullable()->unique()->after('payment_voucher_id');
        }

        if (!Schema::hasColumn('payment_vouchers', 'reference_number')) {
            $table->string('reference_number', 120)->nullable()->after('voucher_number');
        }

        if (!Schema::hasColumn('payment_vouchers', 'currency_code')) {
            $table->string('currency_code', 10)->nullable()->after('due_date');
        }

            if (!Schema::hasColumn('payment_vouchers', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('status');
            }

            if (!Schema::hasColumn('payment_vouchers', 'submitted_by')) {
                $table->unsignedBigInteger('submitted_by')->nullable()->after('submitted_at');
            }

            if (!Schema::hasColumn('payment_vouchers', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('submitted_by');
            }

            if (!Schema::hasColumn('payment_vouchers', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }

            if (!Schema::hasColumn('payment_vouchers', 'approval_notes')) {
                $table->text('approval_notes')->nullable()->after('approved_by');
            }

            if (!Schema::hasColumn('payment_vouchers', 'approval_channel')) {
                $table->string('approval_channel', 20)->nullable()->after('approval_notes');
            }

            if (!Schema::hasColumn('payment_vouchers', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approval_channel');
            }

            if (!Schema::hasColumn('payment_vouchers', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')->nullable()->after('rejected_at');
            }

            if (!Schema::hasColumn('payment_vouchers', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_by');
            }

            if (!Schema::hasColumn('payment_vouchers', 'is_physical_copy_received')) {
                $table->boolean('is_physical_copy_received')->default(false)->after('rejection_reason');
            }

            if (!Schema::hasColumn('payment_vouchers', 'physical_copy_received_at')) {
                $table->timestamp('physical_copy_received_at')->nullable()->after('is_physical_copy_received');
            }

            if (!Schema::hasColumn('payment_vouchers', 'received_by')) {
                $table->unsignedBigInteger('received_by')->nullable()->after('physical_copy_received_at');
            }

            if (!Schema::hasColumn('payment_vouchers', 'received_notes')) {
                $table->text('received_notes')->nullable()->after('received_by');
            }

            if (!Schema::hasColumn('payment_vouchers', 'printed_at')) {
                $table->timestamp('printed_at')->nullable()->after('received_notes');
            }

            if (!Schema::hasColumn('payment_vouchers', 'resubmission_count')) {
                $table->unsignedInteger('resubmission_count')->default(0)->after('printed_at');
            }

            if (!Schema::hasColumn('payment_vouchers', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('resubmission_count');
            }

            if (!Schema::hasColumn('payment_vouchers', 'payment_id')) {
                $table->unsignedBigInteger('payment_id')->nullable()->after('paid_at');
            }

            if (!Schema::hasColumn('payment_vouchers', 'payment_reference')) {
                $table->string('payment_reference', 120)->nullable()->after('payment_id');
            }

            if (!Schema::hasColumn('payment_vouchers', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('payment_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $columns = [
                 'due_date',
                'status',
                'voucher_number',
                'reference_number',
                'currency_code',
                'submitted_at',
                'submitted_by',
                'approved_at',
                'approved_by',
                'approval_notes',
                'approval_channel',
                'rejected_at',
                'rejected_by',
                'rejection_reason',
                'is_physical_copy_received',
                'physical_copy_received_at',
                'received_by',
                'received_notes',
                'printed_at',
                'resubmission_count',
                'paid_at',
                'payment_id',
                'payment_reference',
                'created_by',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('payment_vouchers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
