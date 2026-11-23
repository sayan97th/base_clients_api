<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional ceiling (USD) for what a single order can get from a coupon.
 *
 *   gift_card_reward: the gift card is never worth more than this amount,
 *                     however big the order is.
 *   percentage:       the discount is never bigger than this amount.
 *   fixed_amount:     not used, the fixed value already is the amount.
 *
 * Null means no ceiling, which keeps every existing coupon working as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->decimal('max_discount_amount', 10, 2)->nullable()->after('minimum_purchase_amount');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('max_discount_amount');
        });
    }
};
