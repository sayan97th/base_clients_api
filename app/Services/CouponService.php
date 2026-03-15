<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\LinkBuildingOrder;
use Illuminate\Support\Carbon;

class CouponService
{
    /**
     * Validate a coupon and calculate the discount amount.
     *
     * Returns ['valid' => true, 'discount_amount' => X]
     *      or ['valid' => false, 'message' => '...']
     *
     * @param array<string, float> $dr_tier_amounts  Map of dr_tier_id => subtotal for that tier
     */
    public function validateAndCalculate(
        Coupon $coupon,
        float $order_amount,
        int|string $user_id,
        array $dr_tier_ids = [],
        array $dr_tier_amounts = []
    ): array {
        if (!$coupon->is_active) {
            return ['valid' => false, 'message' => 'This coupon is not active.'];
        }

        if ($coupon->starts_at && Carbon::now()->lt($coupon->starts_at)) {
            return ['valid' => false, 'message' => 'This coupon is not yet valid.'];
        }

        if (Carbon::now()->gt($coupon->expires_at)) {
            return ['valid' => false, 'message' => 'This coupon has expired.'];
        }

        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            return ['valid' => false, 'message' => 'This coupon has reached its usage limit.'];
        }

        if ($coupon->usage_per_user !== null) {
            $user_usage = LinkBuildingOrder::where('coupon_id', $coupon->id)
                ->where('user_id', $user_id)
                ->count();

            if ($user_usage >= $coupon->usage_per_user) {
                return ['valid' => false, 'message' => 'You have already used this coupon the maximum number of times.'];
            }
        }

        if ($coupon->applies_to === 'specific_product') {
            if (!in_array($coupon->dr_tier_id, $dr_tier_ids, strict: true)) {
                return ['valid' => false, 'message' => 'This coupon does not apply to the selected products.'];
            }
        }

        if ($coupon->applies_to === 'minimum_purchase') {
            if ($order_amount < $coupon->minimum_purchase_amount) {
                $min = number_format($coupon->minimum_purchase_amount, 2);

                return ['valid' => false, 'message' => "Your order total does not meet the minimum purchase requirement of \${$min}."];
            }
        }

        $discount_amount = $this->calculateDiscount($coupon, $order_amount, $dr_tier_amounts);

        return ['valid' => true, 'discount_amount' => $discount_amount];
    }

    private function calculateDiscount(Coupon $coupon, float $order_amount, array $dr_tier_amounts): float
    {
        if ($coupon->applies_to === 'specific_product' && $coupon->dr_tier_id !== null) {
            $base_amount = (float) ($dr_tier_amounts[$coupon->dr_tier_id] ?? $order_amount);
        } else {
            $base_amount = $order_amount;
        }

        if ($coupon->discount_type === 'percentage') {
            return round($base_amount * ($coupon->discount_value / 100), 2);
        }

        // fixed_amount — capped at the base amount so total never goes negative
        return min((float) $coupon->discount_value, $base_amount);
    }
}
