<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\LinkBuildingOrderCoupon;
use Illuminate\Support\Carbon;

class CouponService
{
    /**
     * Validate a coupon and calculate the discount amount.
     *
     * Returns ['valid' => true, 'discount_amount' => X]
     *      or ['valid' => false, 'message' => '...']
     *
     * @param array<string, float> $dr_tier_amounts        Map of dr_tier_id => subtotal for that tier
     * @param array<string, float> $product_type_amounts   Map of product_type => subtotal for that type
     */
    public function validateAndCalculate(
        Coupon $coupon,
        float $order_amount,
        int|string $user_id,
        array $dr_tier_ids = [],
        array $dr_tier_amounts = [],
        array $cart_product_types = [],
        array $product_type_amounts = []
    ): array {
        if (!$coupon->is_active) {
            return ['valid' => false, 'message' => 'Coupon not found.'];
        }

        if ($coupon->starts_at && Carbon::now()->lt($coupon->starts_at)) {
            return ['valid' => false, 'message' => 'This coupon is not yet active.'];
        }

        if ($coupon->expires_at && Carbon::now()->gt($coupon->expires_at)) {
            return ['valid' => false, 'message' => 'This coupon has expired.'];
        }

        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            return ['valid' => false, 'message' => 'This coupon has reached its usage limit.'];
        }

        if ($coupon->usage_per_user !== null) {
            $user_usage = LinkBuildingOrderCoupon::where('coupon_id', $coupon->id)
                ->whereHas('order', fn ($q) => $q->where('user_id', $user_id))
                ->count();

            if ($user_usage >= $coupon->usage_per_user) {
                return ['valid' => false, 'message' => 'You have already used this coupon the maximum number of times.'];
            }
        }

        // Check product type restriction
        $coupon_product_types = $coupon->product_types ?? [];
        if (!empty($coupon_product_types) && !empty($cart_product_types)) {
            $matched = array_intersect($coupon_product_types, $cart_product_types);
            if (empty($matched)) {
                $labels = [
                    'link_building'        => 'Link Building',
                    'new_content'          => 'New Content',
                    'content_optimization' => 'Content Optimizations',
                    'content_brief'        => 'Content Briefs',
                ];
                $allowed = implode(', ', array_map(
                    fn ($t) => $labels[$t] ?? ucfirst(str_replace('_', ' ', $t)),
                    $coupon_product_types
                ));
                return ['valid' => false, 'message' => "This coupon is only valid for: {$allowed}."];
            }
        }

        if ($coupon->applies_to === 'specific_product') {
            $coupon->loadMissing('drTiers');
            $coupon_tier_ids = $coupon->drTiers->pluck('id')->toArray();
            $matched         = array_intersect($coupon_tier_ids, $dr_tier_ids);

            if (empty($matched)) {
                return ['valid' => false, 'message' => 'This coupon is not valid for the selected products.'];
            }
        }

        if ($coupon->applies_to === 'minimum_purchase') {
            if ($order_amount < $coupon->minimum_purchase_amount) {
                $min = number_format($coupon->minimum_purchase_amount, 2);

                return ['valid' => false, 'message' => "This coupon requires a minimum purchase of \${$min}."];
            }
        }

        $discount_amount = $this->calculateDiscount($coupon, $order_amount, $dr_tier_amounts, $product_type_amounts);

        return ['valid' => true, 'discount_amount' => $discount_amount];
    }

    private function calculateDiscount(
        Coupon $coupon,
        float $order_amount,
        array $dr_tier_amounts,
        array $product_type_amounts = []
    ): float {
        // Product type restriction takes precedence for base amount calculation
        $coupon_product_types = $coupon->product_types ?? [];
        if (!empty($coupon_product_types) && !empty($product_type_amounts)) {
            $base_amount = 0.0;
            foreach ($coupon_product_types as $product_type) {
                $base_amount += (float) ($product_type_amounts[$product_type] ?? 0);
            }
            if ($base_amount === 0.0) {
                $base_amount = $order_amount;
            }
        } elseif ($coupon->applies_to === 'specific_product') {
            $coupon->loadMissing('drTiers');
            $coupon_tier_ids = $coupon->drTiers->pluck('id')->toArray();

            $base_amount = 0.0;
            foreach ($coupon_tier_ids as $tier_id) {
                $base_amount += (float) ($dr_tier_amounts[$tier_id] ?? 0);
            }

            if ($base_amount === 0.0) {
                $base_amount = $order_amount;
            }
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
