<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_brief_order_coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('content_brief_orders')->cascadeOnDelete();
            $table->string('coupon_id');
            $table->foreign('coupon_id')->references('id')->on('coupons');
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_brief_order_coupons');
    }
};
