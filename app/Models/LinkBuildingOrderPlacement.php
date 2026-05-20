<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LinkBuildingOrderPlacement extends Model
{
    use HasUuids;

    protected $fillable = [
        // Original placement-system fields
        'order_item_id',
        'row_index',
        'keyword',
        'landing_page',
        'exact_match',
        'live_link',
        'dr',
        'completed_date',
        // Dashboard fields (matching backlink_orders schema)
        'order_id',
        'status',
        'team_specific_link_id',
        'link_type',
        'client',
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
        // Client assignment (admin can assign a standalone placement to a user)
        'user_id',
        // Admin team assignment
        'admin_team_id',
        // Admin user assigned to own this order
        'assigned_admin_user_id',
    ];

    protected function casts(): array
    {
        return [
            'row_index'      => 'integer',
            'exact_match'    => 'boolean',
            'dr'             => 'integer',
            'completed_date' => 'datetime',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrderItem::class, 'order_item_id');
    }

    public function reportRow(): HasOne
    {
        return $this->hasOne(OrderReportRow::class, 'order_placement_id');
    }

    public function linkBuilderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'link_builder_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function adminTeam(): BelongsTo
    {
        return $this->belongsTo(AdminTeam::class, 'admin_team_id');
    }

    public function assignedAdminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_user_id');
    }

    /**
     * Returns the full API row shape for the admin dashboard, including computed
     * fields days_left and projected_health. The exact_match boolean is converted
     * to a "Yes"/"No" string to match the frontend's editable select field.
     *
     * For client-purchased placements (order_item_id set, no order_id):
     * - order_id is derived deterministically from the placement UUID
     * - client is derived from the purchase order's user name when not set directly
     */
    public function toApiArray(): array
    {
        [$days_left, $projected_health] = $this->computeDeliveryMetrics();

        // Derive display order_id for client-purchased placements that don't have one.
        // Uses placement UUID to guarantee uniqueness. Saved permanently on first admin edit.
        $order_id = $this->order_id ?? $this->derivedOrderId();

        // Derive client name from linked purchase order user when not set directly.
        $client = $this->client ?? '';
        if ($client === '' && $this->relationLoaded('orderItem')) {
            $purchase_user = $this->orderItem?->order?->user;
            if ($purchase_user) {
                $client = trim(($purchase_user->first_name ?? '') . ' ' . ($purchase_user->last_name ?? ''));
            }
        }
        if ($client === '' && $this->relationLoaded('user') && $this->user) {
            $client = trim(($this->user->first_name ?? '') . ' ' . ($this->user->last_name ?? ''));
        }

        return [
            'id'                        => $this->id,
            'order_id'                  => $order_id,
            'team_specific_link_id'     => $this->team_specific_link_id ?? '',
            'link_type'                 => $this->link_type ?? '',
            'client'                    => $client,
            'keyword'                   => $this->keyword ?? '',
            'landing_page'              => $this->landing_page ?? '',
            'exact_match'               => $this->exact_match ? 'Yes' : 'No',
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
            'user_id'                       => $this->user_id,
            'admin_team_id'                 => $this->admin_team_id,
            'admin_team_name'               => $this->relationLoaded('adminTeam') ? ($this->adminTeam?->name ?? null) : null,
            'admin_team_color'              => $this->relationLoaded('adminTeam') ? ($this->adminTeam?->color ?? null) : null,
            'assigned_admin_user_id'        => $this->assigned_admin_user_id,
            'assigned_admin_user_name'      => $this->relationLoaded('assignedAdminUser')
                ? ($this->assignedAdminUser
                    ? trim(($this->assignedAdminUser->first_name ?? '') . ' ' . ($this->assignedAdminUser->last_name ?? ''))
                    : null)
                : null,
            'assigned_admin_user_avatar'    => $this->relationLoaded('assignedAdminUser')
                ? ($this->assignedAdminUser?->profile_photo_url ?? null)
                : null,
            'parent_order_status'           => $this->relationLoaded('orderItem') ? ($this->orderItem?->order?->status ?? null) : null,
            'created_at'                    => $this->created_at?->toIso8601String(),
            'updated_at'                    => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Generates a deterministic, unique display order_id from the placement UUID.
     * Format: LBO-{first 10 hex chars of UUID uppercased}
     * Example: UUID 550e8400-e29b-41d4-a716-... → LBO-550E8400E2
     */
    private function derivedOrderId(): string
    {
        return 'LBO-' . strtoupper(substr(str_replace('-', '', $this->id), 0, 10));
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

        $days_left  = (int) Carbon::today()->diffInDays($delivery_date, false);
        $turnaround = (int) ($this->estimated_turnaround_days ?? 0);

        if ($turnaround <= 0) {
            return [(string) $days_left, ''];
        }

        $projected_health = (int) round(($days_left / $turnaround) * 100);

        return [(string) $days_left, $projected_health . '%'];
    }
}
