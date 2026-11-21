<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coupon is used per checkout, and one checkout creates one order per
 * product. To show the full checkout where a coupon was used, the history
 * also stores the orders of that checkout the coupon did not discount (for
 * example a product outside the coupon restrictions). is_coupon_applied tells
 * both apart. Every row stored so far is a discounted order, hence the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->boolean('is_coupon_applied')->default(true)->after('product_type');
            $table->index(['coupon_id', 'session_id'], 'coupon_redemptions_coupon_session_index');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->dropIndex('coupon_redemptions_coupon_session_index');
            $table->dropColumn('is_coupon_applied');
        });
    }
};
