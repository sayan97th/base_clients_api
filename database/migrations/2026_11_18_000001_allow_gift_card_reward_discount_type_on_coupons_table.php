<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons can now be a "gift card reward" (money back) instead of a price
 * discount. The discount_type enum only allowed "percentage" and
 * "fixed_amount", so it is widened to a plain string. The list of allowed
 * values is enforced by the coupon form requests instead of the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('discount_type', 32)->default('percentage')->change();
        });
    }

    public function down(): void
    {
        // Reward coupons cannot be represented by the old enum, so they are
        // turned into inactive percentage coupons before narrowing the column.
        DB::table('coupons')
            ->where('discount_type', 'gift_card_reward')
            ->update(['discount_type' => 'percentage', 'is_active' => false]);

        Schema::table('coupons', function (Blueprint $table) {
            $table->enum('discount_type', ['percentage', 'fixed_amount'])->change();
        });
    }
};
