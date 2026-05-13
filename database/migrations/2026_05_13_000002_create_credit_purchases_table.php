<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('package_id');
            $table->string('package_name');
            $table->integer('credits_amount');
            $table->decimal('amount_paid', 8, 2);
            $table->string('payment_intent_id')->unique();
            $table->enum('status', ['completed', 'pending', 'failed', 'refunded'])->default('completed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
    }
};
