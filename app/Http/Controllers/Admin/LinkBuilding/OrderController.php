<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LinkBuildingOrder::with('user:id,first_name,last_name,email')
            ->withCount(['items as items_count' => function ($query) {
                $query->selectRaw('sum(quantity)');
            }]);

        if ($request->has('status') && in_array($request->input('status'), LinkBuildingOrder::STATUSES)) {
            $query->where('status', $request->input('status'));
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15))
            ->through(fn ($order) => [
                'id'          => $order->id,
                'order_title' => $order->order_title,
                'total_amount' => $order->total_amount,
                'status'      => $order->status,
                'created_at'  => $order->created_at,
                'items_count' => (int) ($order->items_count ?? 0),
                'user'        => $order->user,
            ]);

        return response()->json($orders);
    }
}
