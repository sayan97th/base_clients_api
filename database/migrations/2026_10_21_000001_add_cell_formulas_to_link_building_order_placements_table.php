<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spreadsheet formulas typed into the cells of the admin "Link Building Orders"
 * table, keyed by column, e.g. {"final_price": "=[current_price:~0]*1.15"}. The
 * column itself keeps the computed value, so sorting, filtering, exports and the
 * client portal keep reading plain values. References are stored by column key
 * and row offset, never by column letter, so they survive hidden or moved columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->json('cell_formulas')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->dropColumn('cell_formulas');
        });
    }
};
