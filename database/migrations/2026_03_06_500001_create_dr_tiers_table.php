<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dr_tiers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('label', 20);
            $table->string('traffic_range', 50);
            $table->unsignedInteger('word_count');
            $table->decimal('price_per_link', 10, 2);
            $table->boolean('is_most_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dr_tiers');
    }
};
