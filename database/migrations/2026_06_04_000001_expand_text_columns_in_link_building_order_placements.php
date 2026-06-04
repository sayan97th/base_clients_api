<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expand columns that were too narrow to hold real-world data:
     *   - landing_page : VARCHAR(255) → TEXT  (long URLs with query strings)
     *   - live_link    : VARCHAR(2000) → TEXT  (very long URLs / Evernote-style share links)
     *   - live_link_date: VARCHAR(20) → TEXT   (sometimes contains free-text notes, not just dates)
     *   - article      : VARCHAR(2000) → LONGTEXT  (full article bodies can be several KB)
     */
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->text('landing_page')->nullable()->change();
            $table->text('live_link')->nullable()->change();
            $table->text('live_link_date')->nullable()->change();
            $table->longText('article')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->string('landing_page')->nullable()->change();
            $table->string('live_link', 2000)->nullable()->change();
            $table->string('live_link_date', 20)->nullable()->change();
            $table->string('article', 2000)->nullable()->change();
        });
    }
};
