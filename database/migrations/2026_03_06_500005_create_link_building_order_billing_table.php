<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_building_order_billing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('company')->nullable();
            $table->string('address');
            $table->string('city');
            $table->string('state');
            $table->string('country');
            $table->string('postal_code', 20);
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('link_building_orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_building_order_billing');
    }
};
