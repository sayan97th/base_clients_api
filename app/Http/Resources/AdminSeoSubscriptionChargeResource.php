<?php

namespace App\Http\Resources;

use App\Models\SeoSubscriptionCharge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SeoSubscriptionCharge
 */
class AdminSeoSubscriptionChargeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'period_start'    => $this->period_start?->toDateString(),
            'period_end'      => $this->period_end?->toDateString(),
            'period_label'    => $this->period_start ? $this->periodLabel() : null,
            'amount'          => (float) $this->amount,
            'status'          => $this->status,
            'trigger'         => $this->trigger,
            'attempt_count'   => $this->attempt_count,
            'last_attempt_at' => $this->last_attempt_at?->toIso8601String(),
            'next_retry_at'   => $this->next_retry_at?->toIso8601String(),
            'paid_at'         => $this->paid_at?->toIso8601String(),
            'card_label'      => $this->card_label,
            'failure_code'    => $this->failure_code,
            'failure_message' => $this->failure_message,
            'payment_intent_id' => $this->payment_intent_id,
            'charged_by'      => $this->whenLoaded('chargedBy', fn () => $this->chargedBy ? [
                'id'   => $this->chargedBy->id,
                'name' => $this->chargedBy->full_name ?? $this->chargedBy->email,
            ] : null),
            'invoice'         => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'             => $this->invoice->id,
                'unique_id'      => $this->invoice->unique_id,
                'invoice_number' => $this->invoice->invoice_number,
                'status'         => $this->invoice->status,
                'total_amount'   => (float) $this->invoice->total_amount,
            ] : null),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
