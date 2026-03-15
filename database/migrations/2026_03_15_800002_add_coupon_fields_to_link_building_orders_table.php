<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_orders', function (Blueprint $table) {
            $table->uuid('coupon_id')->nullable()->after('payment_intent_id');
            $table->decimal('coupon_discount_amount', 10, 2)->nullable()->after('coupon_id');

            $table->foreign('coupon_id')->references('id')->on('coupons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('link_building_orders', function (Blueprint $table) {
            $table->dropForeign(['coupon_id']);
            $table->dropColumn(['coupon_id', 'coupon_discount_amount']);
        });
    }
};
