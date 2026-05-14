<?php

namespace App\Http\Requests\Admin\Coupon;

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
            'discount_type'           => 'required|in:percentage,fixed_amount',
            'discount_value'          => [
                'required',
                'numeric',
                'gt:0',
                function ($attribute, $value, $fail) {
                    if ($this->discount_type === 'percentage' && $value > 100) {
                        $fail('The discount value must not be greater than 100 when discount type is percentage.');
                    }
                },
            ],
            'applies_to'              => 'required|in:all,specific_product,minimum_purchase',
            'dr_tier_ids'             => [
                Rule::requiredIf($this->applies_to === 'specific_product'),
                'nullable',
                'array',
                'min:1',
            ],
            'dr_tier_ids.*'           => [
                'string',
                Rule::exists('dr_tiers', 'id'),
            ],
            'minimum_purchase_amount' => [
                Rule::requiredIf($this->applies_to === 'minimum_purchase'),
                'nullable',
                'numeric',
                'gt:0',
            ],
            'starts_at'               => [
                'nullable',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) {
                    if ($value && $this->expires_at && strtotime($value) >= strtotime($this->expires_at)) {
                        $fail('The starts at date must be before expires at.');
                    }
                },
            ],
            'expires_at'              => 'nullable|date_format:Y-m-d|after_or_equal:today',
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
            'dr_tier_ids.required'             => 'At least one DR tier must be selected when applies_to is specific_product.',
            'dr_tier_ids.min'                  => 'At least one DR tier must be selected.',
            'dr_tier_ids.*.exists'             => 'One or more selected DR tiers are invalid.',
            'minimum_purchase_amount.required' => 'A minimum purchase amount is required when applies_to is minimum_purchase.',
        ];
    }
}
