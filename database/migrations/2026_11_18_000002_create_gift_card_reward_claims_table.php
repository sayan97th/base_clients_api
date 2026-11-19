<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per checkout that used a gift card reward coupon (for example
 * HAPPYHOLIDAYS). It stores how much the client earned and, once they fill
 * it in, where the gift card should be sent and which brand they prefer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_card_reward_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Checkout session that produced the orders (one claim per checkout).
            $table->string('session_id', 64)->unique();
            $table->json('order_ids');
            $table->json('product_types')->nullable();

            // Coupon data captured at checkout so later coupon edits do not
            // change what the client was promised.
            $table->string('coupon_code', 32);
            $table->decimal('order_total', 10, 2);
            $table->decimal('reward_percentage', 5, 2);
            $table->decimal('reward_amount', 10, 2);

            // Details provided by the client.
            $table->string('recipient_email')->nullable();
            $table->string('gift_card_brand', 32)->nullable();
            $table->string('submission_source', 32)->nullable();

            // pending_details, submitted, fulfilled
            $table->string('status', 32)->default('pending_details');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['coupon_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_reward_claims');
    }
};
