<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            // Allows admins to create standalone placement rows and assign them
            // directly to a client user, so the client can see them in their portal.
            $table->unsignedBigInteger('user_id')->nullable()->after('link_builder_user_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
