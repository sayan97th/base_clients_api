<?php

namespace App\Http\Controllers\Admin\Tracking;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderUpdate;
use Illuminate\Http\JsonResponse;

class TrackingController extends Controller
{
    public function orders(): JsonResponse
    {
        $orders = LinkBuildingOrder::with('user:id,first_name,last_name,email')
            ->withCount(['items as items_count' => function ($query) {
                $query->selectRaw('sum(quantity)');
            }])
            ->withCount('updates as updates_count')
            ->addSelect([
                'last_update_at' => LinkBuildingOrderUpdate::select('created_at')
                    ->whereColumn('order_id', 'link_building_orders.id')
                    ->latest()
                    ->limit(1),
            ])
            ->whereIn('status', ['pending', 'processing'])
            ->where('is_hidden', false)
            ->orderByRaw('updates_count ASC')
            ->orderBy('last_update_at', 'ASC')
            ->get()
            ->map(fn (LinkBuildingOrder $order) => [
                'id'             => $order->id,
                'order_title'    => $order->order_title,
                'total_amount'   => $order->total_amount,
                'status'         => $order->status,
                'created_at'     => $order->created_at,
                'items_count'    => (int) ($order->items_count ?? 0),
                'updates_count'  => (int) ($order->updates_count ?? 0),
                'last_update_at' => $order->last_update_at,
                'user'           => $order->user,
            ]);

        return response()->json(['data' => $orders]);
    }
}
