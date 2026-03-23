<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends order_report_rows to support standalone rows that belong to a report
 * table rather than being tied to a link_building_order_placement.
 *
 * - Adds table_id (FK → order_report_tables)
 * - Adds all row content fields: order_number, link_type, keyword, landing_page,
 *   exact_match, request_date
 * - Makes order_placement_id nullable (existing placement-based rows are kept;
 *   new table-based rows leave this column null)
 * - Drops the UNIQUE constraint on order_placement_id so multiple rows can exist
 *   per placement if needed in the future
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_report_rows', function (Blueprint $table) {
            // MySQL requires dropping the FK before dropping the unique index it backs
            $table->dropForeign(['order_placement_id']);
            $table->dropUnique(['order_placement_id']);
            $table->uuid('order_placement_id')->nullable()->change();
            // Re-add the FK without the unique constraint
            $table->foreign('order_placement_id')
                ->references('id')
                ->on('link_building_order_placements')
                ->onDelete('cascade');

            $table->uuid('table_id')->nullable()->after('id');
            $table->string('order_number', 100)->nullable()->after('table_id');
            $table->string('link_type', 200)->nullable()->after('order_number');
            $table->string('keyword', 500)->nullable()->after('link_type');
            $table->string('landing_page', 2048)->nullable()->after('keyword');
            $table->boolean('exact_match')->default(false)->after('landing_page');
            $table->date('request_date')->nullable()->after('exact_match');

            $table->foreign('table_id')
                ->references('id')
                ->on('order_report_tables')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('order_report_rows', function (Blueprint $table) {
            $table->dropForeign(['table_id']);
            $table->dropForeign(['order_placement_id']);
            $table->dropColumn([
                'table_id',
                'order_number',
                'link_type',
                'keyword',
                'landing_page',
                'exact_match',
                'request_date',
            ]);
            $table->uuid('order_placement_id')->nullable(false)->change();
            $table->unique('order_placement_id');
            $table->foreign('order_placement_id')
                ->references('id')
                ->on('link_building_order_placements')
                ->onDelete('cascade');
        });
    }
};
