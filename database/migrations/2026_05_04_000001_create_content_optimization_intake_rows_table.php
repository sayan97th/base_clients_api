<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_optimization_intake_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('content_optimization_order_items')->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->string('primary_keyword', 500);
            $table->string('secondary_keywords', 1000)->nullable();
            $table->string('content_page_url', 2083);
            $table->timestamps();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_optimization_intake_rows');
    }
};
