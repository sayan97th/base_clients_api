<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_mentions_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->decimal('price_per_month', 10, 2);
            $table->integer('total_placements');
            $table->integer('exclusive_placements');
            $table->integer('core_placements');
            $table->integer('support_placements');
            $table->text('best_for');
            $table->string('tagline');
            $table->boolean('is_most_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_mentions_plans');
    }
};
