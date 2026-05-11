<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_content_intake_rows', function (Blueprint $table) {
            $table->string('secondary_keywords', 500)->nullable()->default(null)->after('keyword_phrase');
        });
    }

    public function down(): void
    {
        Schema::table('new_content_intake_rows', function (Blueprint $table) {
            $table->dropColumn('secondary_keywords');
        });
    }
};
