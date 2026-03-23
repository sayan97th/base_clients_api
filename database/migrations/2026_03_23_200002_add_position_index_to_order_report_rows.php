<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds position_index to order_report_rows.
 *
 * position_index stores the 1-based position of a row within its parent order item
 * (1..quantity). The pair [table_id, position_index] has a unique constraint so that
 * the import endpoint can safely call firstOrCreate([table_id, position_index]) and
 * guarantee idempotency.
 *
 * The column is nullable so that manually created rows (not generated from an import)
 * are unaffected. MySQL treats NULL as distinct in unique indexes, so manually created
 * rows with position_index = NULL never collide with each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_report_rows', function (Blueprint $table) {
            $table->unsignedSmallInteger('position_index')->nullable()->after('table_id');

            $table->unique(['table_id', 'position_index']);
        });
    }

    public function down(): void
    {
        Schema::table('order_report_rows', function (Blueprint $table) {
            $table->dropUnique(['table_id', 'position_index']);
            $table->dropColumn('position_index');
        });
    }
};
