<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_brief_intake_rows', function (Blueprint $table) {
            $table->text('notes')->nullable()->default(null)->after('content_page_url');
        });
    }

    public function down(): void
    {
        Schema::table('content_brief_intake_rows', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
