<?php

namespace App\Rules;

use App\Models\Coupon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ensures a checkout carries at most one promo code. Promo codes cannot be
 * combined with each other, so any request with more than
 * Coupon::MAX_PER_ORDER distinct coupon ids is rejected with the client
 * facing copy "This promo cannot be combined with other offers."
 */
class SinglePromoCodePerOrder implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $distinct_coupon_ids = array_unique(array_filter($value, fn ($coupon_id) => filled($coupon_id)));

        if (count($distinct_coupon_ids) > Coupon::MAX_PER_ORDER) {
            $fail(Coupon::COMBINATION_ERROR_MESSAGE);
        }
    }
}
