<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('notify_all_admins')->default(true);
            $table->json('enabled_user_ids')->default('[]');
            $table->json('custom_emails')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_notification_settings');
    }
};
