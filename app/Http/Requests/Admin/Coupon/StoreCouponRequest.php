<?php

namespace App\Http\Requests\Admin\Coupon;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    public function rules(): array
    {
        $is_dr_tier_scope          = $this->applies_to === Coupon::APPLIES_TO_DR_TIERS;
        $is_minimum_purchase_scope = $this->applies_to === Coupon::APPLIES_TO_MINIMUM_PURCHASE;

        return [
            'code' => [
                'required',
                'string',
                'max:32',
                'alpha_num',
                Rule::unique('coupons', 'code')->whereNull('deleted_at'),
            ],
            'name'                    => 'required|string|max:100',
            'description'             => 'nullable|string|max:500',
            'discount_type'           => 'required|in:percentage,fixed_amount,gift_card_reward',
            'discount_value'          => [
                'required',
                'numeric',
                'gt:0',
                function ($attribute, $value, $fail) {
                    if ($this->discount_type === 'percentage' && $value > 100) {
                        $fail('The discount value must not be greater than 100 when discount type is percentage.');
                    }
                    if ($this->discount_type === 'gift_card_reward' && $value > 100) {
                        $fail('The reward percentage must not be greater than 100.');
                    }
                },
            ],
            'applies_to'              => ['required', Rule::in(Coupon::APPLIES_TO_OPTIONS)],
            'product_types'           => 'nullable|array',
            'product_types.*'         => 'string|in:link_building,new_content,content_optimization,content_brief',
            // DR tiers only matter for the DR Tiers scope, other scopes accept an empty list.
            'dr_tier_ids'             => $is_dr_tier_scope
                ? ['required', 'array', 'min:1']
                : ['nullable', 'array'],
            'dr_tier_ids.*'           => [
                'string',
                Rule::exists('dr_tiers', 'id'),
            ],
            'minimum_purchase_amount' => $is_minimum_purchase_scope
                ? ['required', 'numeric', 'gt:0']
                : ['nullable', 'numeric', 'gt:0'],
            // Optional ceiling per order. Only percentage and gift card coupons use it,
            // the controller clears it for fixed amount coupons.
            'max_discount_amount'     => ['nullable', 'numeric', 'gt:0', 'max:99999999.99'],
            'starts_at'               => [
                'nullable',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) {
                    if ($value && $this->expires_at && strtotime($value) > strtotime($this->expires_at)) {
                        $fail('The starts at date must be on or before the expires at date.');
                    }
                },
            ],
            'expires_at'              => [
                'nullable',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) {
                    if ($value && $value < Coupon::promotionToday()) {
                        $fail('The expires at field must be a date after or equal to today.');
                    }
                },
            ],
            'usage_limit'             => 'nullable|integer|min:1',
            'usage_per_user'          => [
                'nullable',
                'integer',
                'min:1',
                function ($attribute, $value, $fail) {
                    if ($value && $this->usage_limit && $value > $this->usage_limit) {
                        $fail('The usage per user must not exceed the usage limit.');
                    }
                },
            ],
            'is_active'               => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'dr_tier_ids.required'             => 'At least one DR tier must be selected when the discount scope is DR Tiers.',
            'dr_tier_ids.min'                  => 'At least one DR tier must be selected when the discount scope is DR Tiers.',
            'dr_tier_ids.*.exists'             => 'One or more selected DR tiers are invalid.',
            'minimum_purchase_amount.required' => 'A minimum purchase amount is required when the discount scope is Minimum Purchase.',
            'max_discount_amount.gt'           => 'The maximum amount must be greater than 0.',
            'max_discount_amount.numeric'      => 'The maximum amount must be a valid dollar amount.',
        ];
    }
}
