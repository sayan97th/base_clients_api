<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->uuid('admin_team_id')->nullable()->after('user_id');

            $table->foreign('admin_team_id')
                ->references('id')
                ->on('admin_teams')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->dropForeign(['admin_team_id']);
            $table->dropColumn('admin_team_id');
        });
    }
};
