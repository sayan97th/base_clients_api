<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_mentions_order_coupons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('order_id')->constrained('premium_mentions_orders')->cascadeOnDelete();
            $table->foreignUuid('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->decimal('discount_amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_mentions_order_coupons');
    }
};
