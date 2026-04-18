<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_mentions_order_billings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('order_id')->constrained('premium_mentions_orders')->cascadeOnDelete();
            $table->string('company')->nullable();
            $table->string('address');
            $table->string('city');
            $table->string('state');
            $table->string('country');
            $table->string('postal_code', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_mentions_order_billings');
    }
};
