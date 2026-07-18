<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            if (!Schema::hasColumn('billing', 'matching_status')) {
                $table->string('matching_status', 40)->nullable()->after('status')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            if (Schema::hasColumn('billing', 'matching_status')) {
                $table->dropColumn('matching_status');
            }
        });
    }
};