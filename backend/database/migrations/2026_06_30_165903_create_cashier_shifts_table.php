<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cashier_shifts')) {
            Schema::create('cashier_shifts', function (Blueprint $table) {
                $table->bigIncrements('cashier_shift_id');
                $table->unsignedInteger('store_id');
                $table->unsignedInteger('user_id');
                $table->date('business_date');
                $table->string('status', 20)->default('open');
                $table->decimal('opening_balance', 12, 2)->default(0);
                $table->string('opening_note', 500)->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedInteger('closed_by_user_id')->nullable();
                $table->decimal('counted_cash', 12, 2)->nullable();
                $table->decimal('expected_cash', 12, 2)->nullable();
                $table->decimal('variance', 12, 2)->nullable();
                $table->decimal('carry_forward_variance', 12, 2)->default(0);
                $table->string('close_note', 500)->nullable();
                $table->json('summary_snapshot')->nullable();
                $table->timestamps();

                $table->unique(['store_id', 'user_id', 'business_date'], 'cashier_shifts_store_user_date_unique');
                $table->index(['store_id', 'business_date'], 'cashier_shifts_store_date_index');
                $table->index(['user_id', 'business_date'], 'cashier_shifts_user_date_index');
                $table->index(['status'], 'cashier_shifts_status_index');
            });
        } else {
            Schema::table('cashier_shifts', function (Blueprint $table) {
                if (!Schema::hasColumn('cashier_shifts', 'carry_forward_variance')) {
                    $table->decimal('carry_forward_variance', 12, 2)->default(0)->after('variance');
                }
                if (!Schema::hasColumn('cashier_shifts', 'close_note')) {
                    $table->string('close_note', 500)->nullable()->after('carry_forward_variance');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cashier_shifts')) {
            if (Schema::hasColumn('cashier_shifts', 'carry_forward_variance')) {
                Schema::table('cashier_shifts', function (Blueprint $table) {
                    $table->dropColumn('carry_forward_variance');
                });
            }
            if (Schema::hasColumn('cashier_shifts', 'close_note')) {
                Schema::table('cashier_shifts', function (Blueprint $table) {
                    $table->dropColumn('close_note');
                });
            }
        }
    }
};
