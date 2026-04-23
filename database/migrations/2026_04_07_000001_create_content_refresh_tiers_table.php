<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_refresh_tiers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('label', 255);
            $table->string('word_count_range', 100);
            $table->unsignedInteger('turnaround_days');
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_most_popular')->default(false);
            $table->unsignedInteger('max_quantity')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_refresh_tiers');
    }
};
