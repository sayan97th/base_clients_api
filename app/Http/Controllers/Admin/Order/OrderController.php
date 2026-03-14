<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * GET /api/admin/orders?page=N
     */
    public function index(Request $request): JsonResponse
    {
        $orders = LinkBuildingOrder::with(['user', 'items', 'billing', 'invoice'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => $orders->items(),
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'total' => $orders->total(),
        ]);
    }

    /**
     * GET /api/admin/orders/{order}
     */
    public function show(string $id): JsonResponse
    {
        $order = LinkBuildingOrder::with([
            'user:id,first_name,last_name,email',
            'items',
            'billing',
            'invoice.user:id,first_name,last_name,email',
            'invoice.lineItems',
            'invoice.billedTo',
        ])->find($id);

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json([
            'id'                 => $order->id,
            'user_id'            => $order->user_id,
            'order_title'        => $order->order_title,
            'order_notes'        => $order->order_notes,
            'total_amount'       => $order->total_amount,
            'status'             => $order->status,
            'payment_intent_id'  => $order->payment_intent_id,
            'created_at'         => $order->created_at,
            'updated_at'         => $order->updated_at,
            'user'               => $order->user,
            'items'              => $order->items,
            'billing'            => $order->billing,
            'invoice'            => $order->invoice,
        ]);
    }
}