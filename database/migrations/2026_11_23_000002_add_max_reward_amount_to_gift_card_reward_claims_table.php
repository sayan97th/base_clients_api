<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of the coupon's gift card ceiling at the moment of the checkout,
 * next to the reward percentage that is already stored. Later changes to the
 * coupon never change what a client was promised. Null means no ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_card_reward_claims', function (Blueprint $table) {
            $table->decimal('max_reward_amount', 10, 2)->nullable()->after('reward_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('gift_card_reward_claims', function (Blueprint $table) {
            $table->dropColumn('max_reward_amount');
        });
    }
};
