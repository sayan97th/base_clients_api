<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company')->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->string('address')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state_province', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('billing_email')->nullable()->comment('Billing-specific email if different from user email');
            $table->string('billing_phone', 30)->nullable()->comment('Billing-specific phone if different from user phone');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_addresses');
    }
};
