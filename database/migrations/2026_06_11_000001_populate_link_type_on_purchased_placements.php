<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill link_type for existing client-purchased placements that have no link_type set.
     *
     * New checkouts automatically set link_type = "{dr_tier_label} External" (e.g., "DR 40+ External")
     * so that the admin dashboard shows the full External/Internal type and admins can change it.
     * The client dashboard always strips the suffix and shows only the base DR tier (e.g., "DR 40+").
     *
     * This migration applies the same default to historical rows created before this feature.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE link_building_order_placements lbop
            JOIN link_building_order_items lboi ON lboi.id = lbop.order_item_id
            JOIN dr_tiers dt ON dt.id = lboi.dr_tier_id
            SET lbop.link_type = CONCAT(dt.label, ' External')
            WHERE lbop.order_item_id IS NOT NULL
            AND (lbop.link_type IS NULL OR lbop.link_type = '')
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE link_building_order_placements lbop
            JOIN link_building_order_items lboi ON lboi.id = lbop.order_item_id
            SET lbop.link_type = NULL
            WHERE lbop.order_item_id IS NOT NULL
            AND lbop.link_type REGEXP ' External\$'
        ");
    }
};
