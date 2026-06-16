<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_email_batches', function (Blueprint $table) {
            // 'not_sent'    → target clients whose welcome email has never been sent
            // 'all_pending' → target clients who have not yet reset their password
            $table->string('send_mode', 20)->default('not_sent')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_email_batches', function (Blueprint $table) {
            $table->dropColumn('send_mode');
        });
    }
};
