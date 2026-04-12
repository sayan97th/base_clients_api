<?php

namespace App\Http\Requests\LinkBuilding;

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
            'selected_quantities'               => ['required', 'array'],
            'selected_quantities.*'             => ['integer', 'min:1'],
            'keyword_data'                      => ['present', 'array'],
            'keyword_data.*'                    => ['array'],
            'keyword_data.*.*.keyword'          => ['nullable', 'string'],
            'keyword_data.*.*.landing_page'     => ['nullable', 'string'],
            'keyword_data.*.*.exact_match'      => ['boolean'],
            'order_title'                       => ['nullable', 'string', 'max:255'],
            'order_notes'                       => ['nullable', 'string'],
            'applied_coupons'                   => ['array'],
            'applied_coupons.*.coupon_id'       => ['string'],
            'applied_coupons.*.code'            => ['string'],
            'applied_coupons.*.coupon_name'     => ['string'],
            'applied_coupons.*.discount_amount' => ['numeric'],
            'applied_coupons.*.discount_type'   => ['string', 'in:percentage,fixed_amount'],
            'applied_coupons.*.discount_value'  => ['numeric'],
            'coupon_input_code'                 => ['nullable', 'string'],
        ];
    }
}
