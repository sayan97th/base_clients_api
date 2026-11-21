<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Adds to the coupon history the orders that were bought in the same checkout
 * as a coupon use but were not discounted by it, so every past checkout can be
 * shown complete. Existing snapshots are never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('coupons:backfill-redemptions');
    }

    public function down(): void
    {
        // Runs before the column is dropped, so the checkout orders this
        // backfill added can be told apart from the real coupon uses.
        DB::table('coupon_redemptions')->where('is_coupon_applied', false)->delete();
    }
};
