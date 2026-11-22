<?php

namespace App\Http\Requests\Admin\SeoPackages;

use App\Models\PaymentProfile;
use App\Models\SeoPackageSubscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Partial update of a subscription's pricing and billing settings. Every
 * field is optional, so the same endpoint powers the auto renew switch, the
 * card picker and the full "Edit pricing" form.
 */
class UpdateAdminSeoPackageSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $subscription = $this->subscription();
        $pricing_type = $this->input('pricing_type', $subscription?->pricing_type);

        return [
            'pricing_type'             => ['sometimes', Rule::in(SeoPackageSubscription::PRICING_TYPES)],
            'package_id'               => [
                Rule::requiredIf(fn () => $this->has('pricing_type') && $pricing_type === SeoPackageSubscription::PRICING_PACKAGE),
                'nullable',
                'string',
                Rule::exists('seo_packages', 'id')->where('is_active', true),
            ],
            'custom_price'             => [
                Rule::requiredIf(fn () => $this->has('pricing_type') && $pricing_type === SeoPackageSubscription::PRICING_CUSTOM),
                'nullable',
                'numeric',
                'min:0.5',
                'max:1000000',
            ],
            'custom_plan_name'         => ['sometimes', 'nullable', 'string', 'max:120'],
            'invoice_item_label'       => ['sometimes', 'nullable', 'string', 'max:120'],
            'invoice_item_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'auto_renew'               => ['sometimes', 'boolean'],
            'payment_profile_id'       => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('payment_profiles', 'id')->where('user_id', (int) $subscription?->user_id),
            ],
            'next_billing_date'        => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'reminder_days_before'     => ['sometimes', 'nullable', 'integer', 'min:0', 'max:14'],
            'ends_at'                  => ['sometimes', 'nullable', 'date'],
            'notes'                    => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $subscription = $this->subscription();

                if ($validator->errors()->isNotEmpty() || ! $subscription) {
                    return;
                }

                if ($this->boolean('auto_renew') && ! PaymentProfile::where('user_id', $subscription->user_id)->exists()) {
                    $validator->errors()->add(
                        'auto_renew',
                        'This client has no card on file. Ask them to add a card in Billing before turning on auto renew.'
                    );
                }

                $ends_at = $this->input('ends_at');

                if ($ends_at && $subscription->starts_at && $subscription->starts_at->gte($ends_at)) {
                    $validator->errors()->add('ends_at', 'The end date must be after the start date.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'package_id.required'               => 'Select an SEO package, or switch to a custom price.',
            'custom_price.required'             => 'Enter the monthly amount to charge this client.',
            'custom_price.min'                  => 'The monthly amount must be at least $0.50.',
            'next_billing_date.after_or_equal'  => 'The next billing date cannot be in the past.',
            'payment_profile_id.exists'         => 'The selected card does not belong to this client.',
        ];
    }

    private function subscription(): ?SeoPackageSubscription
    {
        return SeoPackageSubscription::find((int) $this->route('id'));
    }
}
