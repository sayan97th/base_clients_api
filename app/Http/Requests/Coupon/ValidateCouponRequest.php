<?php

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class ValidateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'                  => 'required|string|max:32',
            'order_amount'          => 'required|numeric|min:0',
            'dr_tier_ids'           => 'required|array',
            'dr_tier_ids.*'         => 'string',
            // Optional per-tier subtotal map used for specific_product discount calculation
            'dr_tier_amounts'       => 'sometimes|nullable|array',
            'dr_tier_amounts.*'     => 'numeric|min:0',
        ];
    }
}
