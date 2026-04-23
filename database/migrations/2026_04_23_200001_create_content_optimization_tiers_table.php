<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_optimization_tiers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('label');
            $table->string('word_count_range');
            $table->unsignedInteger('turnaround_days');
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_most_popular')->default(false);
            $table->unsignedInteger('max_quantity')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_optimization_tiers');
    }
};
