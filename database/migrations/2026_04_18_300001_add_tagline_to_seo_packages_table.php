<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_packages', function (Blueprint $table) {
            $table->string('tagline')->nullable()->after('best_for');
            $table->text('best_for')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seo_packages', function (Blueprint $table) {
            $table->dropColumn('tagline');
            $table->text('best_for')->nullable(false)->change();
        });
    }
};
