<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            if (!Schema::hasColumn('billing', 'document_type')) {
                $table->string('document_type', 20)->default('sale')->after('notes');
            }

            if (!Schema::hasColumn('billing', 'affects_inventory')) {
                $table->boolean('affects_inventory')->default(true)->after('document_type');
            }

            if (!Schema::hasColumn('billing', 'description')) {
                $table->text('description')->nullable()->after('affects_inventory');
            }

            if (!Schema::hasColumn('billing', 'payment_terms_description')) {
                $table->text('payment_terms_description')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            foreach (['payment_terms_description', 'description', 'affects_inventory', 'document_type'] as $column) {
                if (Schema::hasColumn('billing', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
