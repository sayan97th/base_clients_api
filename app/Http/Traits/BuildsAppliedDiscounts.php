<?php

namespace App\Http\Traits;

use App\Models\Discount;

trait BuildsAppliedDiscounts
{
    /**
     * Derives any non-coupon discount applied to an order from the delta between
     * the pre-discount subtotal and the final total (after subtracting coupon savings).
     *
     * Returns an array of discount entries in the same shape as coupon entries so
     * the frontend can render both uniformly. Returns [] when no automatic discount
     * was applied or the difference is too small to be meaningful (< $0.01).
     */
    protected function buildAppliedDiscounts(
        float $subtotal_before_discount,
        float $total_amount,
        float $coupon_savings
    ): array {
        $implied_discount = round($subtotal_before_discount - $total_amount - $coupon_savings, 2);

        if ($implied_discount < 0.01) {
            return [];
        }

        $implied_rate = $subtotal_before_discount > 0
            ? round($implied_discount / $subtotal_before_discount * 100, 4)
            : 0.0;

        // Look up the active Discount record whose rate is closest to the implied rate.
        // This gives us the human-readable name and description stored by the admin.
        $matched = Discount::where('is_active', true)
            ->whereRaw('ABS(discount_rate - ?) < 0.5', [$implied_rate])
            ->orderByRaw('ABS(discount_rate - ?) ASC', [$implied_rate])
            ->first();

        return [[
            'name'            => $matched?->name ?? 'Bulk Discount',
            'description'     => $matched?->description ?? sprintf('Automatic %.0f%% discount applied', $implied_rate),
            'discount_type'   => $matched?->discount_type ?? 'percentage',
            'discount_rate'   => $matched?->discount_rate ?? $implied_rate,
            'discount_amount' => $implied_discount,
        ]];
    }
}
