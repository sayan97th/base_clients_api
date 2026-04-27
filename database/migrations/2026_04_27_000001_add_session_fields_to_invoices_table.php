<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('session_id')->nullable()->after('order_id');
            $table->string('session_title')->nullable()->after('session_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
            $table->dropColumn(['session_id', 'session_title']);
        });
    }
};
