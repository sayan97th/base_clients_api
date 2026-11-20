<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Fills the coupon redemption history with the coupon uses that happened
 * before it existed. Only missing snapshots are created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('coupons:backfill-redemptions');
    }

    public function down(): void
    {
        // The history table is dropped by the previous migration.
    }
};
