<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-adds the unique constraint on order_report_rows.order_placement_id.
 *
 * The constraint was dropped in migration 100002 to allow rows without a linked
 * placement. Re-adding it as a partial unique (MySQL treats NULL as distinct in
 * unique indexes) ensures the placement-based import is idempotent — each
 * link_building_order_placement can be linked to at most one report row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_report_rows', function (Blueprint $table) {
            $table->unique('order_placement_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_report_rows', function (Blueprint $table) {
            $table->dropUnique(['order_placement_id']);
        });
    }
};
