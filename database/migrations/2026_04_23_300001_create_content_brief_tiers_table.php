<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_brief_tiers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('label')->comment('Display name shown on the product card');
            $table->unsignedInteger('turnaround_days')->comment('Business days to deliver');
            $table->decimal('price', 10, 2)->comment('Price in USD');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_most_popular')->default(false);
            $table->unsignedInteger('max_quantity')->nullable()->comment('Max quantity per order; null = unlimited');
            $table->boolean('is_hidden')->default(false);
            $table->integer('sort_order')->default(0)->comment('Ascending display order');
            $table->timestamps();

            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_brief_tiers');
    }
};
