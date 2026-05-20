<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_admin_user_id')->nullable()->after('admin_team_id');
            $table->foreign('assigned_admin_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->dropForeign(['assigned_admin_user_id']);
            $table->dropColumn('assigned_admin_user_id');
        });
    }
};
