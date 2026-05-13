<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_packages', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('credits');
            $table->decimal('price', 8, 2);
            $table->decimal('original_price', 8, 2);
            $table->integer('discount_pct')->default(0);
            $table->string('description');
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_packages');
    }
};
