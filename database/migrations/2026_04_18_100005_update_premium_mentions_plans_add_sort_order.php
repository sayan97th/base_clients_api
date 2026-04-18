<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('premium_mentions_plans', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('is_active');
            $table->text('best_for')->nullable()->change();
            $table->string('tagline')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('premium_mentions_plans', function (Blueprint $table) {
            $table->dropColumn('sort_order');
            $table->text('best_for')->nullable(false)->change();
            $table->string('tagline')->nullable(false)->change();
        });
    }
};
