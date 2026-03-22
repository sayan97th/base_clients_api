<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_orders', function (Blueprint $table) {
            $table->decimal('subtotal_before_discount', 10, 2)->nullable()->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('link_building_orders', function (Blueprint $table) {
            $table->dropColumn('subtotal_before_discount');
        });
    }
};
