<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_packages', function (Blueprint $table) {
            $table->string('headline')->nullable()->after('name');
            $table->text('ideal_for')->nullable()->after('best_for');
            $table->dropColumn('tagline');
        });
    }

    public function down(): void
    {
        Schema::table('seo_packages', function (Blueprint $table) {
            $table->dropColumn(['headline', 'ideal_for']);
            $table->string('tagline')->nullable()->after('best_for');
        });
    }
};
