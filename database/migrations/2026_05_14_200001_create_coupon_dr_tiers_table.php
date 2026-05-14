<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_dr_tiers', function (Blueprint $table) {
            $table->id();
            $table->uuid('coupon_id');
            $table->string('dr_tier_id');
            $table->timestamps();

            $table->foreign('coupon_id')
                ->references('id')
                ->on('coupons')
                ->onDelete('cascade');

            $table->foreign('dr_tier_id')
                ->references('id')
                ->on('dr_tiers')
                ->onDelete('cascade');

            $table->unique(['coupon_id', 'dr_tier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_dr_tiers');
    }
};
