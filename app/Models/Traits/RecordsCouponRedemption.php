<?php

namespace App\Models\Traits;

use App\Services\CouponRedemptionRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Used by the order coupon pivot models. Once the checkout transaction
 * commits (so the order items already exist) the coupon use is copied into
 * the immutable coupon_redemptions history.
 */
trait RecordsCouponRedemption
{
    public static function bootRecordsCouponRedemption(): void
    {
        static::created(function (Model $order_coupon) {
            DB::afterCommit(
                fn () => app(CouponRedemptionRecorder::class)->safelyRecordOrderCoupon($order_coupon)
            );
        });
    }
}
