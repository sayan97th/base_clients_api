<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->string('live_link')->nullable()->after('exact_match');
            $table->unsignedSmallInteger('dr')->nullable()->after('live_link');
            $table->timestamp('completed_date')->nullable()->after('dr');
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->dropColumn(['live_link', 'dr', 'completed_date']);
        });
    }
};
