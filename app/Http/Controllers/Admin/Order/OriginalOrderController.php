<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderSnapshot;
use Illuminate\Http\JsonResponse;

/**
 * Read only "Original Order" view: a Link Building order exactly as the client
 * submitted it at checkout (see LinkBuildingOrderSnapshot), next to the values
 * its placements have now, so the team can see and recover what was changed on
 * the Link Building Orders table afterwards.
 */
class OriginalOrderController extends Controller
{
    /**
     * GET /api/admin/orders/{order_id}/original
     *
     * Responds with the order, its read only copies (oldest first: the checkout
     * copy, then one per later details submission) and the current values of its
     * placements keyed by placement id. A placement missing from current_placements
     * was deleted from the table.
     */
    public function show(string $order_id): JsonResponse
    {
        $order = LinkBuildingOrder::with([
            'user:id,first_name,last_name,email,company',
            'items.placements',
            'invoice',
            'snapshots.capturedBy:id,first_name,last_name,email',
        ])->find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Original order details are kept for Link Building orders only.'], 404);
        }

        $current_placements = $order->items
            ->flatMap->placements
            ->mapWithKeys(fn ($placement) => [$placement->id => [
                'order_id'     => $placement->displayOrderId(),
                'keyword'      => $placement->keyword,
                'landing_page' => $placement->landing_page,
                'exact_match'  => (bool) $placement->exact_match,
                'link_type'    => $placement->link_type,
                'client'       => $placement->client,
                'status'       => $placement->status,
                'request_date' => $placement->request_date,
            ]]);

        return response()->json([
            'data' => [
                'order'              => [
                    'id'           => $order->id,
                    'order_title'  => $order->order_title,
                    'status'       => $order->status,
                    'session_id'   => $order->session_id,
                    'created_at'   => $order->created_at?->toIso8601String(),
                    'product_type' => 'link_building',
                    'user'         => $order->user ? [
                        'id'         => $order->user->id,
                        'first_name' => $order->user->first_name,
                        'last_name'  => $order->user->last_name,
                        'email'      => $order->user->email,
                        'company'    => $order->user->company,
                    ] : null,
                ],
                'invoice'            => $order->invoice ? [
                    'invoice_number' => $order->invoice->invoice_number,
                    'status'         => $order->invoice->status,
                    'payment_method' => $order->invoice->payment_method,
                ] : null,
                'snapshots'          => $order->snapshots
                    ->map(fn (LinkBuildingOrderSnapshot $snapshot) => [
                        'id'             => $snapshot->id,
                        'type'           => $snapshot->type,
                        'payment_method' => $snapshot->payment_method,
                        'is_backfilled'  => $snapshot->is_backfilled,
                        'captured_at'    => $snapshot->created_at?->toIso8601String(),
                        'captured_by'    => $snapshot->capturedBy ? [
                            'id'   => $snapshot->capturedBy->id,
                            'name' => trim($snapshot->capturedBy->first_name . ' ' . $snapshot->capturedBy->last_name) ?: $snapshot->capturedBy->email,
                        ] : null,
                        'payload'        => $snapshot->payload,
                    ])
                    ->values()
                    ->all(),
                'current_placements' => (object) $current_placements->all(),
            ],
        ]);
    }
}
