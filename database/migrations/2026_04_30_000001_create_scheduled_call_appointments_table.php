<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_call_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_uri')->nullable();
            $table->string('invitee_uri')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'])->default('pending');
            $table->datetime('scheduled_at');
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('reschedule_reason')->nullable();
            $table->string('preferred_dates')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_call_appointments');
    }
};
