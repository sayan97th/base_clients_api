<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_content_intake_rows', function (Blueprint $table) {
            $table->string('type_of_content', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('new_content_intake_rows', function (Blueprint $table) {
            $table->string('type_of_content', 100)->nullable(false)->change();
        });
    }
};
