<?php

namespace App\Http\Controllers\Admin\Tracking;

use App\Http\Controllers\Controller;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderUpdate;
use App\Models\NewContentOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    private const PRODUCT_MODELS = [
        'link_building'        => LinkBuildingOrder::class,
        'new_content'          => NewContentOrder::class,
        'content_optimization' => ContentOptimizationOrder::class,
        'content_brief'        => ContentBriefOrder::class,
    ];

    public function orders(Request $request): JsonResponse
    {
        $status_filter       = $request->input('status');
        $needs_update_filter = $request->boolean('needs_update');
        $product_type_filter = $request->input('product_type');

        $valid_statuses = ['pending', 'processing', 'completed', 'cancelled', 'payment_pending'];

        $product_models = filled($product_type_filter) && isset(self::PRODUCT_MODELS[$product_type_filter])
            ? [$product_type_filter => self::PRODUCT_MODELS[$product_type_filter]]
            : self::PRODUCT_MODELS;

        $all_orders = collect();

        foreach ($product_models as $product_type => $model_class) {
            $table_name = (new $model_class)->getTable();

            $query = $model_class::with('user:id,first_name,last_name,email')
                ->withCount(['items as items_count' => function ($q) {
                    $q->selectRaw('sum(quantity)');
                }])
                ->withCount('updates as updates_count')
                ->addSelect([
                    'last_update_at' => LinkBuildingOrderUpdate::select('created_at')
                        ->whereColumn('order_id', "{$table_name}.id")
                        ->latest()
                        ->limit(1),
                ]);

            if ($product_type === 'link_building') {
                $query->where('is_hidden', false);
            }

            if (filled($status_filter) && in_array($status_filter, $valid_statuses)) {
                $query->where('status', $status_filter);
            }

            if ($needs_update_filter) {
                $query->where('status', 'pending')->whereDoesntHave('updates');
            }

            $orders = $query->get()->map(fn($order) => [
                'id'             => $order->id,
                'product_type'   => $product_type,
                'order_title'    => $order->order_title,
                'total_amount'   => $order->total_amount,
                'status'         => $order->status,
                'created_at'     => $order->created_at?->toISOString(),
                'items_count'    => (int)($order->items_count ?? 0),
                'updates_count'  => (int)($order->updates_count ?? 0),
                'last_update_at' => $order->last_update_at,
                'user'           => $order->user,
            ]);

            $all_orders = $all_orders->merge($orders);
        }

        $sorted = $all_orders
            ->sort(function ($a, $b) {
                // Priority 1: orders with no updates come first (never responded to)
                $priority_a = $a['updates_count'] === 0 ? 0 : 1;
                $priority_b = $b['updates_count'] === 0 ? 0 : 1;
                if ($priority_a !== $priority_b) {
                    return $priority_a <=> $priority_b;
                }

                // Priority 2: oldest last activity first — falls back to created_at for orders with no updates
                $time_a = strtotime($a['last_update_at'] ?? $a['created_at']);
                $time_b = strtotime($b['last_update_at'] ?? $b['created_at']);
                if ($time_a !== $time_b) {
                    return $time_a <=> $time_b;
                }

                // Priority 3: FIFO tiebreaker by order creation date
                return strtotime($a['created_at']) <=> strtotime($b['created_at']);
            })
            ->values();

        return response()->json(['data' => $sorted]);
    }
}
