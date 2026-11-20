<?php

namespace App\Console\Commands\Coupon;

use App\Models\CouponRedemption;
use App\Models\GiftCardRewardClaim;
use App\Services\CouponRedemptionRecorder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Copies the coupon uses recorded before the coupon_redemptions history
 * existed. Safe to run more than once: snapshots that already exist are kept
 * untouched, so prices captured at checkout are never overwritten.
 */
class BackfillCouponRedemptions extends Command
{
    protected $signature = 'coupons:backfill-redemptions';
    protected $description = 'Store a price snapshot for every coupon use that is not in the coupon redemption history yet';

    public function handle(CouponRedemptionRecorder $recorder): int
    {
        $count_before = CouponRedemption::count();
        $failures     = 0;

        foreach (array_keys(CouponRedemptionRecorder::ORDER_COUPON_SOURCES) as $order_coupon_class) {
            $order_coupon_class::query()->orderBy('created_at')->chunk(200, function ($order_coupons) use ($recorder, &$failures) {
                foreach ($order_coupons as $order_coupon) {
                    try {
                        $recorder->recordOrderCoupon($order_coupon);
                    } catch (Throwable $exception) {
                        $failures++;
                        $this->warn("Skipped {$order_coupon->order_id}: {$exception->getMessage()}");
                    }
                }
            });
        }

        GiftCardRewardClaim::query()->orderBy('created_at')->chunk(200, function ($claims) use ($recorder, &$failures) {
            foreach ($claims as $claim) {
                try {
                    $recorder->recordGiftCardClaim($claim);
                } catch (Throwable $exception) {
                    $failures++;
                    $this->warn("Skipped gift card claim {$claim->id}: {$exception->getMessage()}");
                }
            }
        });

        $created = CouponRedemption::count() - $count_before;
        $this->info("Coupon redemption history updated. {$created} new snapshots, {$failures} skipped.");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
