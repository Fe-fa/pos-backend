<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->bigIncrements('store_id');
            $table->string('store_name');
            $table->string('location')->nullable();
            $table->string('currency', 10)->default('USD');
            $table->string('logo_url')->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('pin', 50)->nullable();
            $table->string('physical_address')->nullable();
            $table->string('email_address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
