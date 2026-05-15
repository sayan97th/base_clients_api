<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_teams', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_capacity')->default(50)->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('admin_teams', function (Blueprint $table) {
            $table->dropColumn('max_capacity');
        });
    }
};
