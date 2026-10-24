<?php

namespace App\Services;

use App\Mail\OrderStatusChangeMail;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderPlacement;
use Illuminate\Support\Facades\Mail;

/**
 * Keeps the status of a client purchase (LinkBuildingOrder) in step with the
 * statuses of its placements on the Link Building Orders table:
 *   - Every placement "Live" → order becomes "completed"
 *   - Any other combination  → order becomes "processing"
 */
class LinkBuildingParentOrderStatusService
{
    /**
     * Syncs the parent order of a client-purchased placement. When the order status
     * actually changes, an email is queued to the client unless $notify_client is
     * false, as when a history restore puts a mistaken edit back.
     */
    public function sync(LinkBuildingOrderPlacement $placement, bool $notify_client = true): void
    {
        $placement->loadMissing('orderItem.order.user');

        $order_item   = $placement->orderItem;
        $parent_order = $order_item?->order;

        if (! $parent_order) {
            return;
        }

        $placement_statuses = LinkBuildingOrderPlacement::whereHas('orderItem', function ($q) use ($parent_order) {
            $q->where('order_id', $parent_order->id);
        })->pluck('status');

        $new_order_status = $placement_statuses->every(fn ($s) => $s === 'Live')
            ? 'completed'
            : 'processing';

        if ($parent_order->status === $new_order_status) {
            return;
        }

        $parent_order->update(['status' => $new_order_status]);

        $user = $parent_order->user;

        if ($user && $notify_client) {
            Mail::to($user->email)->queue(
                new OrderStatusChangeMail(
                    user: $user,
                    status: $new_order_status,
                    order_id: $parent_order->id,
                    order_title: $parent_order->order_title,
                    purchased_at: $parent_order->created_at,
                    link_count: $placement_statuses->count(),
                    dr_tier_summary: $this->buildDrTierSummary($parent_order),
                )
            );
        }
    }

    /**
     * Builds a comma-separated summary of the DR tier(s) purchased on an order
     * (e.g. "DR 30-49, DR 50-59"), so the client-facing email can identify not just
     * which order this is, but what was actually purchased.
     */
    private function buildDrTierSummary(LinkBuildingOrder $parent_order): ?string
    {
        $parent_order->loadMissing('items.drTier');

        $labels = $parent_order->items
            ->pluck('drTier.label')
            ->filter()
            ->unique()
            ->values();

        return $labels->isEmpty() ? null : $labels->implode(', ');
    }
}
