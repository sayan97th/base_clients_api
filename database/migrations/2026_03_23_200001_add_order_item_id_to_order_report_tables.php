<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds order_item_id to order_report_tables so that the import endpoint can
 * perform a createOrUpdate per order item (firstOrCreate by [report_id, order_item_id]).
 * The column is nullable so that manually created tables (not tied to an item) continue
 * to work unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_report_tables', function (Blueprint $table) {
            $table->uuid('order_item_id')->nullable()->after('report_id');

            $table->foreign('order_item_id')
                ->references('id')
                ->on('link_building_order_items')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('order_report_tables', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropColumn('order_item_id');
        });
    }
};
