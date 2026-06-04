<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            // row_index is only relevant for client-purchased placements (order_item_id set).
            // Admin-created standalone dashboard rows do not have a row_index, so the column
            // must be nullable to avoid a "Field doesn't have a default value" error on insert.
            $table->unsignedSmallInteger('row_index')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->unsignedSmallInteger('row_index')->nullable(false)->change();
        });
    }
};
