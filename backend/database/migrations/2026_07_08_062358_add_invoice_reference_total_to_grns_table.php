<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grns', function (Blueprint $table) {
            if (!Schema::hasColumn('grns', 'invoice_reference_total')) {
                $table->decimal('invoice_reference_total', 14, 2)->nullable()->after('invoice_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grns', function (Blueprint $table) {
            if (Schema::hasColumn('grns', 'invoice_reference_total')) {
                $table->dropColumn('invoice_reference_total');
            }
        });
    }
};
