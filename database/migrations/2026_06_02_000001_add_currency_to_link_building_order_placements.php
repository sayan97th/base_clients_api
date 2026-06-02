<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('final_price');
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
