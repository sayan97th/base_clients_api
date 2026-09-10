<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_comparison_row_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('seo_comparison_row_id')->constrained('seo_comparison_rows')->cascadeOnDelete();
            $table->string('seo_package_id');
            $table->foreign('seo_package_id')->references('id')->on('seo_packages')->cascadeOnDelete();
            $table->string('value')->nullable();
            $table->timestamps();

            $table->unique(['seo_comparison_row_id', 'seo_package_id'], 'seo_comparison_row_values_row_package_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_comparison_row_values');
    }
};
