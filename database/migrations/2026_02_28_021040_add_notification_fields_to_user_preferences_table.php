<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->string('notification_channel', 30)->default('email_and_portal')->after('marketing_emails');
            $table->boolean('team_order_updates')->default(true)->after('notification_channel');
            $table->boolean('push_notifications_enabled')->default(false)->after('team_order_updates');
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn(['notification_channel', 'team_order_updates', 'push_notifications_enabled']);
        });
    }
};
