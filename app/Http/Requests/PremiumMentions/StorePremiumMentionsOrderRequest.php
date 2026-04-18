<?php

namespace App\Http\Requests\PremiumMentions;

use App\Models\PremiumMentionsPlan;
use Illuminate\Foundation\Http\FormRequest;

class StorePremiumMentionsOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id'              => ['required', 'string', 'exists:premium_mentions_plans,id'],
            'order_notes'          => ['nullable', 'string', 'max:2000'],
            'total_amount'         => ['required', 'numeric', 'min:0'],
            'coupon_ids'           => ['nullable', 'array'],
            'coupon_ids.*'         => ['string', 'exists:coupons,id'],
            'billing'              => ['required', 'array'],
            'billing.company'      => ['nullable', 'string', 'max:255'],
            'billing.address'      => ['required', 'string', 'max:255'],
            'billing.city'         => ['required', 'string', 'max:100'],
            'billing.state'        => ['required', 'string', 'max:100'],
            'billing.country'      => ['required', 'string', 'max:100'],
            'billing.postal_code'  => ['required', 'string', 'max:20'],
            'payment'              => ['required', 'array'],
            'payment.payment_method_id' => ['required', 'string'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $plan_id = $this->input('plan_id');

            if ($plan_id && !$validator->errors()->has('plan_id')) {
                $plan = PremiumMentionsPlan::find($plan_id);

                if (!$plan || !$plan->is_active) {
                    $validator->errors()->add('plan_id', 'The selected plan is not available.');
                }
            }
        });
    }
}
