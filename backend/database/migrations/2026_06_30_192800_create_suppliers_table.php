<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            // Note: Since you're using 'supplier_id' instead of default 'id',
            // your foreign keys need to specify this column explicitly.
            $table->bigIncrements('supplier_id'); 
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('supplier_name');
            $table->boolean('is_active')->default(1);
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};