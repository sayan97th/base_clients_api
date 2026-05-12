<?php

namespace App\Http\Controllers\Client\NewContent;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingOrderUpdate;
use App\Models\NewContentOrder;
use Illuminate\Http\JsonResponse;

class NewContentOrderUpdateController extends Controller
{
    public function index(string $order_id): JsonResponse
    {
        $order = NewContentOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->user_id !== auth()->id()) {
            return response()->json(['message' => 'You do not have permission to view this order.'], 403);
        }

        $updates = $order->updates()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (LinkBuildingOrderUpdate $update) => [
                'id'            => $update->id,
                'title'         => $update->title,
                'message'       => $update->message,
                'status_change' => $update->status_change,
                'created_at'    => $update->created_at,
            ]);

        return response()->json(['data' => $updates]);
    }
}
