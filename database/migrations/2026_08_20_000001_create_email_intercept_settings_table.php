<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_intercept_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('intercept_admin_emails')->default(false);
            $table->boolean('intercept_client_emails')->default(false);
            $table->json('recipient_emails')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_intercept_settings');
    }
};
