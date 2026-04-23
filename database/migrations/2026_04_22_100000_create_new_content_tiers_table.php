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
        Schema::create('new_content_tiers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('label')->comment('Tier label (e.g., 500 Word Optimized SEO Article)');
            $table->string('turnaround_time')->comment('Turnaround time (e.g., 6 Business Days)');
            $table->decimal('price', 10, 2)->comment('Price in USD');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_most_popular')->default(false);
            $table->unsignedInteger('max_quantity')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->integer('sort_order')->default(0)->comment('Sort order for display');
            $table->timestamps();

            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('new_content_tiers');
    }
};
