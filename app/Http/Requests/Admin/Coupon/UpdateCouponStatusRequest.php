<?php

namespace App\Http\Requests\Admin\Coupon;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Enables or disables a coupon. Only the status is validated on purpose, so
 * an admin can always switch a coupon off quickly, even when other stored
 * fields would fail the full edit validation (an old expiry date, a scope
 * without tiers, and so on).
 */
class UpdateCouponStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'is_active.required' => 'Choose whether the coupon is active or disabled.',
            'is_active.boolean'  => 'The coupon status must be active or disabled.',
        ];
    }
}
