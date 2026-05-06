<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_session_comments', function (Blueprint $table) {
            $table->string('session_id')->nullable()->change();
            $table->uuid('order_id')->nullable()->index()->after('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_session_comments', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropColumn('order_id');
            $table->string('session_id')->nullable(false)->change();
        });
    }
};
