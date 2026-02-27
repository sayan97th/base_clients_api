<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_calls', function (Blueprint $table) {
            $table->id();
            $table->string('contact_name', 255);
            $table->string('contact_email', 255);
            $table->enum('call_type', ['discovery', 'strategy', 'review', 'support']);
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->unsignedSmallInteger('duration');
            $table->enum('status', ['scheduled', 'completed', 'cancelled', 'no_show'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('contact_email');
            $table->index('status');
            $table->index('scheduled_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_calls');
    }
};
