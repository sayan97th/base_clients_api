<?php

use App\Models\LinkBuildingOrderPlacement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalized copies of the Partnership domain and the Landing Page URL of every
 * row on the admin "Link Building Orders" table, so the dashboard can find, in one
 * indexed query, the orders that already used a domain for the same landing page
 * (see LinkBuildingDomainUsageService). The existing rows are backfilled with the
 * query builder so no update events are broadcast and updated_at is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->string('partnership_key', 255)->nullable()->after('partnership');
            $table->string('landing_page_key', 255)->nullable()->after('landing_page');
            $table->index(['partnership_key', 'landing_page_key'], 'lbop_partnership_landing_page_key_index');
        });

        DB::table('link_building_order_placements')
            ->select(['id', 'partnership', 'landing_page'])
            ->where(function ($query) {
                $query->whereNotNull('partnership')->orWhereNotNull('landing_page');
            })
            ->orderBy('id')
            ->chunkById(500, function ($placements) {
                foreach ($placements as $placement) {
                    $keys = LinkBuildingOrderPlacement::usageKeysFor($placement->partnership, $placement->landing_page);

                    if ($keys['partnership_key'] !== null || $keys['landing_page_key'] !== null) {
                        DB::table('link_building_order_placements')->where('id', $placement->id)->update($keys);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->dropIndex('lbop_partnership_landing_page_key_index');
            $table->dropColumn(['partnership_key', 'landing_page_key']);
        });
    }
};
