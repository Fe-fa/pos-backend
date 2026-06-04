<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('shift_name')->nullable()->after('default_store_id');
            $table->time('shift_start')->nullable()->after('shift_name');
            $table->time('shift_end')->nullable()->after('shift_start');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['shift_name', 'shift_start', 'shift_end']);
        });
    }
};
