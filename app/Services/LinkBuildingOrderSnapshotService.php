<?php

namespace App\Services;

use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderSnapshot;

/**
 * Keeps a read only copy of every Link Building order as the client submitted it
 * (see LinkBuildingOrderSnapshot): at checkout, whatever the payment method, and
 * each time the keywords and landing pages of a deferred order are filled in.
 */
class LinkBuildingOrderSnapshotService
{
    /** Bumped when the payload shape changes, so old copies can still be read. */
    public const SCHEMA_VERSION = 1;

    /** Copy taken right after checkout created the order, its items and its placements. */
    public function capturePlaced(LinkBuildingOrder $order, string $payment_method): LinkBuildingOrderSnapshot
    {
        return $this->capture($order, LinkBuildingOrderSnapshot::TYPE_PLACED, $payment_method);
    }

    /** Copy taken when the details of a deferred order are submitted from the fill-in-later form. */
    public function captureDetailsSubmitted(LinkBuildingOrder $order): LinkBuildingOrderSnapshot
    {
        return $this->capture($order, LinkBuildingOrderSnapshot::TYPE_DETAILS_SUBMITTED, null);
    }

    /**
     * Copy of an order placed before snapshots existed, from the data it has now,
     * flagged as backfilled because it may already include later edits. Returns
     * null when the order already has a "placed" copy.
     */
    public function backfill(LinkBuildingOrder $order): ?LinkBuildingOrderSnapshot
    {
        $has_snapshot = $order->snapshots()->where('type', LinkBuildingOrderSnapshot::TYPE_PLACED)->exists();
        if ($has_snapshot) {
            return null;
        }

        $order->loadMissing('invoice');

        return LinkBuildingOrderSnapshot::create([
            'order_id'       => $order->id,
            'type'           => LinkBuildingOrderSnapshot::TYPE_PLACED,
            'payment_method' => $order->invoice?->payment_method,
            'payload'        => $this->buildPayload($order),
            'is_backfilled'  => true,
            'captured_by_id' => null,
        ]);
    }

    /**
     * The order as stored right now: customer, billing, discounts, items and every
     * placement with the values the client entered.
     */
    public function buildPayload(LinkBuildingOrder $order): array
    {
        // Reloaded, not loadMissing(): checkout adds items and placements after the
        // order model was created, so relations loaded earlier would be stale.
        $order->load(['user', 'items.drTier', 'items.placements', 'billing', 'orderCoupons.coupon']);

        $user    = $order->user;
        $billing = $order->billing;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'order'          => [
                'id'                       => $order->id,
                'order_title'              => $order->order_title,
                'order_notes'              => $order->order_notes,
                'status'                   => $order->status,
                'subtotal_before_discount' => $order->subtotal_before_discount,
                'total_amount'             => $order->total_amount,
                'session_id'               => $order->session_id,
                'session_title'            => $order->session_title,
                'created_at'               => $order->created_at?->toIso8601String(),
            ],
            'customer'       => $user ? [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'company'    => $user->company,
            ] : null,
            'billing'        => $billing ? [
                'company'     => $billing->company,
                'address'     => $billing->address,
                'city'        => $billing->city,
                'state'       => $billing->state,
                'country'     => $billing->country,
                'postal_code' => $billing->postal_code,
            ] : null,
            'coupons'        => $order->orderCoupons
                ->filter(fn ($order_coupon) => $order_coupon->coupon !== null)
                ->map(fn ($order_coupon) => [
                    'code'            => $order_coupon->coupon->code,
                    'discount_type'   => $order_coupon->coupon->discount_type,
                    'discount_value'  => $order_coupon->coupon->discount_value,
                    'discount_amount' => (float) $order_coupon->discount_amount,
                ])
                ->values()
                ->all(),
            'items'          => $order->items->map(fn ($item) => [
                'id'            => $item->id,
                'dr_tier_id'    => $item->dr_tier_id,
                'dr_tier_label' => $item->drTier?->label,
                'quantity'      => $item->quantity,
                'unit_price'    => $item->unit_price,
                'subtotal'      => $item->subtotal,
                'placements'    => $item->placements
                    ->sortBy('row_index')
                    ->map(fn ($placement) => [
                        'id'           => $placement->id,
                        'order_id'     => $placement->displayOrderId(),
                        'row_index'    => $placement->row_index,
                        'keyword'      => $placement->keyword,
                        'landing_page' => $placement->landing_page,
                        'exact_match'  => (bool) $placement->exact_match,
                        'link_type'    => $placement->link_type,
                        'client'       => $placement->client,
                        'status'       => $placement->status,
                        'request_date' => $placement->request_date,
                    ])
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }

    private function capture(LinkBuildingOrder $order, string $type, ?string $payment_method): LinkBuildingOrderSnapshot
    {
        return LinkBuildingOrderSnapshot::create([
            'order_id'       => $order->id,
            'type'           => $type,
            'payment_method' => $payment_method,
            'payload'        => $this->buildPayload($order),
            'is_backfilled'  => false,
            'captured_by_id' => auth()->id(),
        ]);
    }
}
