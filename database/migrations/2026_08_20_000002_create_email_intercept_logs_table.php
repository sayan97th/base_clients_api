<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_intercept_logs', function (Blueprint $table) {
            $table->id();
            $table->string('mailable_class');
            $table->string('audience');
            $table->string('original_recipient_email');
            $table->string('subject')->nullable();
            $table->json('copied_to_emails');
            $table->timestamp('intercepted_at');
            $table->timestamps();

            $table->index('intercepted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_intercept_logs');
    }
};
