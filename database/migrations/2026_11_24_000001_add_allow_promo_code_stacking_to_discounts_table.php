<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions never stack by default: when a client applies a promo code, the
 * automatic discounts (for example the Bulk Link Building Discount) are
 * removed from the order. Admins can opt a discount back in per record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->boolean('allow_promo_code_stacking')->default(false)->after('applies_to');
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn('allow_promo_code_stacking');
        });
    }
};
