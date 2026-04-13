<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacklinkOrder extends Model
{
    use HasUuids;

    protected $table = 'backlink_orders';

    protected $fillable = [
        'order_id',
        'team_specific_link_id',
        'link_type',
        'client',
        'keyword',
        'landing_page',
        'exact_match',
        'notes',
        'request_date',
        'estimated_delivery_date',
        'estimated_turnaround_days',
        'link_builder_user_id',
        'link_builder',
        'pen_name',
        'partnership',
        'article_title',
        'article',
        'status',
        'live_link',
        'live_link_date',
        'dr_lbs',
        'posting_fee_lbs',
        'current_traffic',
        'dr_formula',
        'current_poc',
        'current_price',
        'lb_tl_approval',
        'approval_date',
        'final_price',
    ];

    public function linkBuilderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'link_builder_user_id');
    }

    /**
     * Returns the full API row shape including the computed fields
     * days_left and projected_health. Used by broadcast events and
     * the controller's formatRow helper.
     */
    public function toApiArray(): array
    {
        [$days_left, $projected_health] = $this->computeDeliveryMetrics();

        return [
            'id'                        => $this->id,
            'order_id'                  => $this->order_id ?? '',
            'team_specific_link_id'     => $this->team_specific_link_id ?? '',
            'link_type'                 => $this->link_type ?? '',
            'client'                    => $this->client ?? '',
            'keyword'                   => $this->keyword ?? '',
            'landing_page'              => $this->landing_page ?? '',
            'exact_match'               => $this->exact_match ?? 'No',
            'notes'                     => $this->notes ?? '',
            'request_date'              => $this->request_date ?? '',
            'estimated_delivery_date'   => $this->estimated_delivery_date ?? '',
            'estimated_turnaround_days' => (string) ($this->estimated_turnaround_days ?? ''),
            'days_left'                 => $days_left,
            'projected_health'          => $projected_health,
            'link_builder'              => $this->link_builder ?? '',
            'pen_name'                  => $this->pen_name ?? '',
            'partnership'               => $this->partnership ?? '',
            'article_title'             => $this->article_title ?? '',
            'article'                   => $this->article ?? '',
            'status'                    => $this->status ?? 'New Request',
            'live_link'                 => $this->live_link ?? '',
            'live_link_date'            => $this->live_link_date ?? '',
            'dr_lbs'                    => $this->dr_lbs ?? '',
            'posting_fee_lbs'           => $this->posting_fee_lbs ?? '',
            'current_traffic'           => $this->current_traffic ?? '',
            'dr_formula'                => $this->dr_formula ?? '',
            'current_poc'               => $this->current_poc ?? '',
            'current_price'             => $this->current_price ?? '',
            'lb_tl_approval'            => $this->lb_tl_approval ?? '',
            'approval_date'             => $this->approval_date ?? '',
            'final_price'               => $this->final_price ?? '',
            'created_at'                => $this->created_at?->toIso8601String(),
            'updated_at'                => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Computes days_left and projected_health from the stored date strings.
     *
     * @return array{string, string}  [$days_left, $projected_health]
     */
    private function computeDeliveryMetrics(): array
    {
        if (empty($this->estimated_delivery_date)) {
            return ['', ''];
        }

        try {
            $delivery_date = Carbon::createFromFormat('m/d/Y', $this->estimated_delivery_date)->startOfDay();
        } catch (\Exception) {
            return ['', ''];
        }

        $days_left = (int) Carbon::today()->diffInDays($delivery_date, false);
        $turnaround = (int) ($this->estimated_turnaround_days ?? 0);

        if ($turnaround <= 0) {
            return [(string) $days_left, ''];
        }

        $projected_health = (int) round(($days_left / $turnaround) * 100);

        return [(string) $days_left, $projected_health . '%'];
    }
}
