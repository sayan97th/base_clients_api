<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_building_order_placements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_item_id');
            $table->unsignedSmallInteger('row_index');
            $table->string('keyword')->nullable();
            $table->string('landing_page')->nullable();
            $table->boolean('exact_match')->default(false);
            $table->timestamps();

            $table->foreign('order_item_id')->references('id')->on('link_building_order_items')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_building_order_placements');
    }
};
