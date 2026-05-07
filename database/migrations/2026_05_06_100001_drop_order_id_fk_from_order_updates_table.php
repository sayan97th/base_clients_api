<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_updates', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_updates', function (Blueprint $table) {
            $table->foreign('order_id')
                ->references('id')
                ->on('link_building_orders')
                ->cascadeOnDelete();
        });
    }
};
