<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('invoice_id');
            $table->string('event');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name');
            $table->string('actor_initials', 2);
            $table->enum('actor_type', ['system', 'client', 'admin']);
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->foreign('actor_id')->references('id')->on('users')->onDelete('set null');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_history');
    }
};
