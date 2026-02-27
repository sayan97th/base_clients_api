<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone', 50)->default('UTC');
            $table->string('language', 10)->default('en');
            $table->enum('interested_in', ['nothing', 'links', 'content', 'both'])->default('nothing');
            $table->boolean('email_notifications')->default(true);
            $table->boolean('marketing_emails')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
