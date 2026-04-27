<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_orders', function (Blueprint $table) {
            $table->string('session_id')->nullable()->index()->after('payment_intent_id');
            $table->string('session_title')->nullable()->after('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('link_building_orders', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
            $table->dropColumn(['session_id', 'session_title']);
        });
    }
};
