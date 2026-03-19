<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dr_tiers', function (Blueprint $table) {
            $table->unsignedInteger('max_quantity')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('dr_tiers', function (Blueprint $table) {
            $table->dropColumn('max_quantity');
        });
    }
};
