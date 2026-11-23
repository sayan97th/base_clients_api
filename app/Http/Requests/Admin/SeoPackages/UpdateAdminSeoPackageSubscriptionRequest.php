<?php

namespace App\Http\Requests\Admin\SeoPackages;

use App\Models\PaymentProfile;
use App\Models\SeoPackageSubscription;
use App\Services\SeoSubscriptionBillingService;
use App\Support\BillingCalendar;
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
            // Compared against "today" in the admin's own timezone, see after().
            'next_billing_date'        => ['sometimes', 'nullable', 'date'],
            'timezone'                 => ['sometimes', 'nullable', 'string', 'max:64'],
            'reminder_days_before'     => ['sometimes', 'nullable', 'integer', 'min:0', 'max:' . SeoPackageSubscription::MAX_REMINDER_DAYS],
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

                $next_billing_date = $this->input('next_billing_date');

                if ($next_billing_date) {
                    if (BillingCalendar::isPast($next_billing_date, $this->input('timezone'))) {
                        $validator->errors()->add('next_billing_date', 'The next billing date cannot be in the past.');
                    }

                    // A month is billed once: moving the schedule into a month
                    // that is already paid would charge the client twice.
                    $paid_charge = app(SeoSubscriptionBillingService::class)
                        ->findPaidChargeForMonth($subscription, $next_billing_date);

                    if ($paid_charge) {
                        $validator->errors()->add(
                            'next_billing_date',
                            "{$paid_charge->periodLabel()} is already paid. Pick a date in a later month so the client is not charged twice."
                        );
                    }
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
            'payment_profile_id.exists'         => 'The selected card does not belong to this client.',
            'reminder_days_before.integer'      => 'The reminder must be a whole number of days.',
            'reminder_days_before.max'          => 'The reminder can be sent at most ' . SeoPackageSubscription::MAX_REMINDER_DAYS . ' days before the charge.',
        ];
    }

    private function subscription(): ?SeoPackageSubscription
    {
        return SeoPackageSubscription::find((int) $this->route('id'));
    }
}
