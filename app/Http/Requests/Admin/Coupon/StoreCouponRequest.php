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
            'description'             => 'nullable|string|max:255',
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
            'dr_tier_id'              => [
                Rule::requiredIf($this->applies_to === 'specific_product'),
                'nullable',
                'string',
                Rule::exists('dr_tiers', 'id'),
            ],
            'minimum_purchase_amount' => [
                Rule::requiredIf($this->applies_to === 'minimum_purchase'),
                'nullable',
                'numeric',
                'gt:0',
            ],
            'starts_at'               => 'nullable|date|before:expires_at',
            'expires_at'              => 'required|date|after:now',
            'usage_limit'             => 'nullable|integer|min:1',
            'usage_per_user'          => 'nullable|integer|min:1',
            'is_active'               => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'dr_tier_id.required'              => 'A DR tier is required when applies_to is specific_product.',
            'minimum_purchase_amount.required'  => 'A minimum purchase amount is required when applies_to is minimum_purchase.',
        ];
    }
}
