<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sme_authored_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('selected_tiers');
            $table->json('billing_address');
            $table->string('email');
            $table->unsignedInteger('total_amount');
            $table->enum('status', ['pending', 'paid', 'processing', 'completed', 'cancelled'])->default('paid');
            $table->string('payment_intent_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sme_authored_orders');
    }
};
