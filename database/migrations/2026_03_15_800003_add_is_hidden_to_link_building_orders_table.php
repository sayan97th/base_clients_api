<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_orders', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('link_building_orders', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
