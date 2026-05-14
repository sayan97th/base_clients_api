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
            'code'                      => 'required|string|max:32',
            'order_amount'              => 'required|numeric|min:0',
            'dr_tier_ids'               => 'nullable|array',
            'dr_tier_ids.*'             => 'string',
            'dr_tier_amounts'           => 'nullable|array',
            'dr_tier_amounts.*'         => 'numeric|min:0',
            'cart_product_types'        => 'nullable|array',
            'cart_product_types.*'      => 'string|in:link_building,new_content,content_optimization,content_brief',
            'product_type_amounts'      => 'nullable|array',
            'product_type_amounts.*'    => 'numeric|min:0',
        ];
    }
}
