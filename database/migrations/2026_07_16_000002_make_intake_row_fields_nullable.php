<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred intake ("Pending Details") lets a client check out and fill the
 * keyword / target URL details later — so an intake row may legitimately be
 * saved incomplete. These key columns must therefore be nullable, mirroring the
 * earlier change that made new_content_intake_rows.type_of_content nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_content_intake_rows', function (Blueprint $table) {
            $table->string('keyword_phrase', 500)->nullable()->change();
        });

        Schema::table('content_optimization_intake_rows', function (Blueprint $table) {
            $table->string('primary_keyword', 500)->nullable()->change();
            $table->string('content_page_url', 2083)->nullable()->change();
        });

        Schema::table('content_brief_intake_rows', function (Blueprint $table) {
            $table->string('primary_keyword', 500)->nullable()->change();
            $table->string('content_page_url', 2083)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('new_content_intake_rows', function (Blueprint $table) {
            $table->string('keyword_phrase', 500)->nullable(false)->change();
        });

        Schema::table('content_optimization_intake_rows', function (Blueprint $table) {
            $table->string('primary_keyword', 500)->nullable(false)->change();
            $table->string('content_page_url', 2083)->nullable(false)->change();
        });

        Schema::table('content_brief_intake_rows', function (Blueprint $table) {
            $table->string('primary_keyword', 500)->nullable(false)->change();
            $table->string('content_page_url', 2083)->nullable(false)->change();
        });
    }
};
