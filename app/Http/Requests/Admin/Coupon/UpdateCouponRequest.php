<?php

namespace App\Http\Requests\Admin\Coupon;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCouponRequest extends FormRequest
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
        $coupon_id   = $this->route('id');
        $applies_to  = $this->input('applies_to');
        $discount_type = $this->input('discount_type');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:32',
                'alpha_num',
                Rule::unique('coupons', 'code')->ignore($coupon_id)->whereNull('deleted_at'),
            ],
            'name'                    => 'sometimes|string|max:100',
            'description'             => 'sometimes|nullable|string|max:500',
            'discount_type'           => 'sometimes|in:percentage,fixed_amount',
            'discount_value'          => [
                'sometimes',
                'numeric',
                'gt:0',
                function ($attribute, $value, $fail) use ($discount_type) {
                    $type = $discount_type ?? $this->input('discount_type');
                    if ($type === 'percentage' && $value > 100) {
                        $fail('The discount value must not be greater than 100 when discount type is percentage.');
                    }
                },
            ],
            'applies_to'              => 'sometimes|in:all,specific_product,minimum_purchase',
            'dr_tier_id'              => [
                'sometimes',
                Rule::requiredIf($applies_to === 'specific_product'),
                'nullable',
                'string',
                Rule::exists('dr_tiers', 'id'),
            ],
            'minimum_purchase_amount' => [
                'sometimes',
                Rule::requiredIf($applies_to === 'minimum_purchase'),
                'nullable',
                'numeric',
                'gt:0',
            ],
            'starts_at'               => 'sometimes|nullable|date_format:Y-m-d|before:expires_at',
            'expires_at'              => 'sometimes|date_format:Y-m-d|after_or_equal:today',
            'usage_limit'             => 'sometimes|nullable|integer|min:1',
            'usage_per_user'          => 'sometimes|nullable|integer|min:1',
            'is_active'               => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'dr_tier_id.required'             => 'A DR tier is required when applies_to is specific_product.',
            'minimum_purchase_amount.required' => 'A minimum purchase amount is required when applies_to is minimum_purchase.',
        ];
    }
}
