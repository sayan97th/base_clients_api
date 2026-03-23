<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dr_tiers', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_dr')->nullable()->after('dr_label');
            $table->unsignedSmallInteger('max_dr')->nullable()->after('min_dr');
        });
    }

    public function down(): void
    {
        Schema::table('dr_tiers', function (Blueprint $table) {
            $table->dropColumn(['min_dr', 'max_dr']);
        });
    }
};
