<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_updates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->unsignedBigInteger('created_by_id');
            $table->string('title', 255);
            $table->text('message');
            $table->enum('status_change', ['pending', 'processing', 'completed', 'cancelled'])->nullable();
            $table->boolean('send_email')->default(true);
            $table->timestamps();

            $table->foreign('order_id')
                ->references('id')
                ->on('link_building_orders')
                ->cascadeOnDelete();

            $table->foreign('created_by_id')
                ->references('id')
                ->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_updates');
    }
};
