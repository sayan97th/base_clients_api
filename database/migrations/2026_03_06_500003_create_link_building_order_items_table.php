<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_building_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('dr_tier_id');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('link_building_orders')->onDelete('cascade');
            $table->foreign('dr_tier_id')->references('id')->on('dr_tiers')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_building_order_items');
    }
};
