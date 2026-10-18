<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppression_logs', function (Blueprint $table) {
            $table->id();
            $table->string('mailable_class');
            $table->string('email_type')->nullable();
            $table->string('recipient_email');
            $table->string('subject')->nullable();
            $table->string('reason');
            $table->timestamp('suppressed_at');
            $table->timestamps();

            $table->index('suppressed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppression_logs');
    }
};
