<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

class UpsertCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                              => ['required', 'array'],
            'items.*.cart_item_id'               => ['required', 'string', 'uuid'],
            'items.*.product_type'               => ['required', 'string', 'in:link_building,content_optimization,new_content,content_brief'],
            'items.*.tier_id'                    => ['required', 'string', 'max:255'],
            'items.*.tier_name'                  => ['required', 'string', 'max:255'],
            'items.*.quantity'                   => ['required', 'integer', 'min:1'],
            'items.*.unit_price'                 => ['required', 'numeric', 'min:0'],
            'items.*.keyword_data'                              => ['nullable', 'array'],
            'items.*.keyword_data.*.keyword'                    => ['nullable', 'string', 'max:500'],
            'items.*.keyword_data.*.landing_page'               => ['nullable', 'string', 'max:2048'],
            'items.*.keyword_data.*.exact_match'                => ['nullable', 'boolean'],
            'items.*.intake_data'                               => ['nullable', 'array'],
            'items.*.intake_data.*'                             => ['nullable', 'array'],
            'items.*.intake_data.*.*.keyword_phrase'            => ['nullable', 'string', 'max:500'],
            'items.*.intake_data.*.*.type_of_content'           => ['nullable', 'string', 'in:Blog Article,Product Page,Home Page,About Us Page,Other'],
            'items.*.intake_data.*.*.notes'                     => ['nullable', 'string', 'max:1000'],

            'applied_coupons'                    => ['nullable', 'array'],
            'applied_coupons.*.coupon_id'        => ['required', 'string', 'uuid'],
            'applied_coupons.*.code'             => ['required', 'string', 'max:100'],
            'applied_coupons.*.coupon_name'      => ['required', 'string', 'max:255'],
            'applied_coupons.*.discount_amount'  => ['required', 'numeric', 'min:0'],
            'applied_coupons.*.discount_type'    => ['required', 'string', 'in:percentage,fixed_amount'],
            'applied_coupons.*.discount_value'   => ['required', 'numeric', 'min:0'],

            'coupon_input_code'                  => ['nullable', 'string', 'max:100'],
            'order_title'                        => ['nullable', 'string', 'max:500'],
            'order_notes'                        => ['nullable', 'string', 'max:5000'],
        ];
    }
}
