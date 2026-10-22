<?php

use App\Models\LinkBuildingOrderPlacement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Formats the prices already saved on the admin "Link Building Orders" table the
 * way new ones are saved (see LinkBuildingOrderPlacement::formatPrice()), so a
 * price typed as "150" before shows as "$150.00" like the rest. Text that is not a
 * plain amount is left untouched. Written with the query builder so no update
 * events are broadcast and updated_at is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        $price_columns = LinkBuildingOrderPlacement::PRICE_COLUMNS;

        DB::table('link_building_order_placements')
            ->select(['id', 'currency', ...$price_columns])
            ->where(function ($query) use ($price_columns) {
                foreach ($price_columns as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->orderBy('id')
            ->chunkById(500, function ($placements) use ($price_columns) {
                foreach ($placements as $placement) {
                    $changes = [];
                    foreach ($price_columns as $column) {
                        $formatted = LinkBuildingOrderPlacement::formatPrice($placement->{$column}, $placement->currency ?? 'USD');
                        if ($formatted !== $placement->{$column}) {
                            $changes[$column] = $formatted;
                        }
                    }

                    if (! empty($changes)) {
                        DB::table('link_building_order_placements')->where('id', $placement->id)->update($changes);
                    }
                }
            });
    }

    public function down(): void
    {
        // The original spelling of each price is not kept, the formatted amounts stay.
    }
};
