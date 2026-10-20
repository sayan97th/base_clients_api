<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalog of partnership domains and their niche (the "Category" column of the
        // domain databases in the BASE link sheet). The dashboard reads it to show the
        // niche of a domain while choosing it as a Partnership, and to fill the Niche
        // cell of an order when its Partnership changes.
        Schema::create('link_building_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Normalized host: lowercase, no protocol, no "www." and no path.
            $table->string('domain', 255)->unique();
            $table->string('niche', 255)->nullable()->index();
            $table->timestamps();
        });

        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->string('niche', 255)->nullable()->after('partnership');
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->dropColumn('niche');
        });

        Schema::dropIfExists('link_building_domains');
    }
};
