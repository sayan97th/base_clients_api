<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Note: order_report_tables are virtual — derived from link_building_order_items joined with dr_tiers.
// This migration creates order_report_rows instead, which store delivery details per placement.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_report_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_placement_id')->unique();
            $table->enum('status', ['pending', 'live', 'rejected'])->default('pending');
            $table->string('live_link', 2048)->nullable();
            $table->date('live_link_date')->nullable();
            $table->unsignedTinyInteger('dr')->nullable();
            $table->timestamps();

            $table->foreign('order_placement_id')
                ->references('id')
                ->on('link_building_order_placements')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_report_rows');
    }
};
