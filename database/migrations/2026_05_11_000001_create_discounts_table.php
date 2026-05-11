<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('discount_type')->default('bulk'); // 'bulk'
            $table->decimal('discount_rate', 5, 2)->default(10.00); // percentage, e.g. 10.00 = 10%
            $table->unsignedInteger('min_quantity')->default(12); // minimum items to trigger the discount
            $table->string('applies_to')->default('link_building'); // 'link_building', 'all'
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
