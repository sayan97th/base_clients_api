<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference table of partnership domains, the portal version of the "SEO Metrics"
 * and "Current POC" tabs of the BASE Google Sheet. The Link Table looks up the
 * Partnership of each order here (like a VLOOKUP) to fill DR Formula, Current
 * Traffic, Current POC and Current Price. `domain_key` is the normalized domain
 * (no protocol, no "www.", lowercase) used for matching.
 *
 * `metrics_synced_at` is set while the domain has DR and traffic values, and
 * `partner_synced_at` while it is in the current partner list. A domain that
 * leaves the partner list keeps its row with a null `partner_synced_at`, which
 * is what turns its orders into "SITE REMOVED".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_building_partner_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('domain_key', 255)->unique();
            $table->string('domain', 255);
            $table->string('dr', 50)->nullable();
            $table->string('traffic', 50)->nullable();
            $table->string('current_poc', 255)->nullable();
            $table->string('current_price', 50)->nullable();
            $table->timestamp('metrics_synced_at')->nullable();
            $table->timestamp('partner_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_building_partner_metrics');
    }
};
