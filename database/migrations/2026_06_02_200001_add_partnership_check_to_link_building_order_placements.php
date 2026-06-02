<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->string('partnership_check', 50)->nullable()->after('partnership');
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->dropColumn('partnership_check');
        });
    }
};
