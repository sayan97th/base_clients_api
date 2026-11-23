<?php

namespace App\Http\Resources;

use App\Services\SeoSubscriptionPaymentHealthService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeoPackageSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'status'     => $this->status,
            'starts_at'  => $this->starts_at?->toIso8601String(),
            'ends_at'    => $this->ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'plan_name'         => $this->displayPlanName(),
            'pricing_type'      => $this->pricing_type,
            'monthly_price'     => (float) $this->monthly_price,
            'auto_renew'        => (bool) $this->auto_renew,
            'next_billing_date' => $this->next_billing_date?->toDateString(),
            'payment_status'    => $this->payment_status,
            // Lets the client see a failed renewal or a missing card in the portal.
            'payment_health'    => app(SeoSubscriptionPaymentHealthService::class)->evaluateForClient($this->resource),
            'package'    => $this->whenLoaded('package', fn () => $this->package ? [
                'id'              => $this->package->id,
                'name'            => $this->package->name,
                'slug'            => $this->package->slug,
                'price_per_month' => $this->package->price_per_month,
            ] : null),
        ];
    }
}
