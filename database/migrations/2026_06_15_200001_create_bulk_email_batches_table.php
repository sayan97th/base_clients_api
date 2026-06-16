<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_email_batches', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('processing'); // processing, completed, stopped
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->boolean('is_stopped')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_email_batches');
    }
};
