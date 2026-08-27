<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            // Widen to match its sibling metric columns (posting_fee_lbs, current_traffic,
            // dr_formula are all 50 chars) — the tighter 20-char cap was truncating DR
            // values pasted straight from the external BASE link sheet.
            $table->string('dr_lbs', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->string('dr_lbs', 20)->nullable()->change();
        });
    }
};
