<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('stripe_payment_method_id', 255)->unique();
            $table->string('card_brand', 50);
            $table->char('last_four', 4);
            $table->char('expiry_month', 2);
            $table->string('expiry_year', 4);
            $table->string('cardholder_name', 255)->nullable();
            $table->tinyInteger('is_default')->default(0);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_profiles');
    }
};
