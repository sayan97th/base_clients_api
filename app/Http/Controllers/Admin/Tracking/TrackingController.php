<?php

namespace App\Http\Controllers\Admin\Tracking;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function orders(Request $request): JsonResponse
    {
        $query = LinkBuildingOrder::with('user:id,first_name,last_name,email')
            ->withCount(['items as items_count' => function ($q) {
                $q->selectRaw('sum(quantity)');
            }])
            ->withCount('updates as updates_count')
            ->addSelect([
                'last_update_at' => LinkBuildingOrderUpdate::select('created_at')
                    ->whereColumn('order_id', 'link_building_orders.id')
                    ->latest()
                    ->limit(1),
            ])
            ->where('is_hidden', false);

        if ($request->filled('status') && in_array($request->input('status'), LinkBuildingOrder::STATUSES)) {
            $query->where('status', $request->input('status'));
        }

        // needs_update: pending orders that have never received an update
        if ($request->boolean('needs_update')) {
            $query->where('status', 'pending')
                ->whereDoesntHave('updates');
        }

        $orders = $query
            // Priority 1: orders with no updates come first (never responded to)
            ->orderByRaw('CASE WHEN updates_count = 0 THEN 0 ELSE 1 END ASC')
            // Priority 2: oldest last activity first — falls back to created_at for orders with no updates
            ->orderByRaw('COALESCE(last_update_at, created_at) ASC')
            // Priority 3: FIFO tiebreaker by order creation date
            ->orderBy('created_at', 'ASC')
            ->get()
            ->map(fn(LinkBuildingOrder $order) => [
                'id' => $order->id,
                'order_title' => $order->order_title,
                'total_amount' => $order->total_amount,
                'status' => $order->status,
                'created_at' => $order->created_at,
                'items_count' => (int)($order->items_count ?? 0),
                'updates_count' => (int)($order->updates_count ?? 0),
                'last_update_at' => $order->last_update_at,
                'user' => $order->user,
            ]);

        return response()->json(['data' => $orders]);
    }
}
