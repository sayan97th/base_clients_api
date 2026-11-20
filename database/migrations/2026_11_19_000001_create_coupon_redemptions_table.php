<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable history of every coupon use. Each row is a snapshot taken at
 * checkout: the coupon terms, the client and every item with the price that
 * was actually charged. There are no foreign keys on purpose, so deleting or
 * editing a coupon, an order, a product tier or a user never changes or
 * removes the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Coupon terms at the moment it was used.
            $table->uuid('coupon_id')->index();
            $table->string('coupon_code');
            $table->string('coupon_name')->nullable();
            $table->string('discount_type');
            $table->decimal('discount_value', 10, 2);

            // Order reference.
            $table->uuid('order_id')->index();
            $table->string('product_type', 40);
            $table->string('session_id')->nullable()->index();
            $table->string('order_title')->nullable();
            $table->string('order_status')->nullable();

            // Client at the moment of the purchase.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('client_name')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_company')->nullable();
            $table->string('client_organization')->nullable();

            // Items with the price charged at checkout: tier_id, label, quantity, unit_price, subtotal.
            $table->json('items');

            $table->decimal('subtotal_before_discount', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->decimal('reward_amount', 10, 2)->default(0);
            $table->uuid('gift_card_claim_id')->nullable()->index();

            $table->timestamp('redeemed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['coupon_id', 'product_type', 'order_id'], 'coupon_redemptions_coupon_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
