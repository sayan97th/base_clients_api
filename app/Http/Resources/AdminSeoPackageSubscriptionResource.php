<?php

namespace App\Http\Resources;

use App\Models\SeoPackageSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SeoPackageSubscription
 */
class AdminSeoPackageSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'status'                   => $this->status,
            'starts_at'                => $this->starts_at?->toIso8601String(),
            'ends_at'                  => $this->ends_at?->toIso8601String(),
            'cancelled_at'             => $this->cancelled_at?->toIso8601String(),
            'notes'                    => $this->notes,
            'created_at'               => $this->created_at?->toIso8601String(),
            'updated_at'               => $this->updated_at?->toIso8601String(),

            'plan_name'                => $this->displayPlanName(),
            'pricing_type'             => $this->pricing_type,
            'monthly_price'            => (float) $this->monthly_price,
            'invoice_item_label'       => $this->invoiceItemLabel(),
            'invoice_item_description' => $this->invoice_item_description,

            'auto_renew'               => (bool) $this->auto_renew,
            'auto_renew_disabled_at'   => $this->auto_renew_disabled_at?->toIso8601String(),
            'billing_anchor_day'       => $this->billing_anchor_day,
            'next_billing_date'        => $this->next_billing_date?->toDateString(),
            'reminder_days_before'     => $this->reminder_days_before,
            'last_reminder_sent_for'   => $this->last_reminder_sent_for?->toDateString(),
            'payment_status'           => $this->payment_status,
            'payment_profile_id'       => $this->payment_profile_id,
            'payment_profile'          => $this->whenLoaded('paymentProfile', fn () => $this->paymentProfile ? [
                'id'           => $this->paymentProfile->id,
                'card_brand'   => $this->paymentProfile->card_brand,
                'last_four'    => $this->paymentProfile->last_four,
                'expiry_month' => $this->paymentProfile->expiry_month,
                'expiry_year'  => $this->paymentProfile->expiry_year,
            ] : null),

            'user'                     => $this->whenLoaded('user', fn () => [
                'id'           => $this->user->id,
                'first_name'   => $this->user->first_name,
                'last_name'    => $this->user->last_name,
                'email'        => $this->user->email,
                'company'      => $this->user->company,
                'organization' => $this->user->organization?->name,
            ]),
            'package'                  => $this->whenLoaded('package', fn () => $this->package ? [
                'id'              => $this->package->id,
                'name'            => $this->package->name,
                'slug'            => $this->package->slug,
                'price_per_month' => $this->package->price_per_month,
            ] : null),

            'last_charge'              => $this->whenLoaded('latestCharge', fn () => $this->latestCharge
                ? new AdminSeoSubscriptionChargeResource($this->latestCharge)
                : null),
            'charges'                  => AdminSeoSubscriptionChargeResource::collection($this->whenLoaded('charges')),
        ];
    }
}
