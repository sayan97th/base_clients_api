<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bans', function (Blueprint $table) {
            $table->dropForeign(['banned_by']);
            $table->unsignedBigInteger('banned_by')->nullable()->change();
            $table->foreign('banned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_bans', function (Blueprint $table) {
            $table->dropForeign(['banned_by']);
            $table->unsignedBigInteger('banned_by')->nullable(false)->change();
            $table->foreignId('banned_by')->constrained('users');
        });
    }
};
