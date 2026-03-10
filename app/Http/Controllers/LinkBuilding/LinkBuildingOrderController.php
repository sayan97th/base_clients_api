<?php

namespace App\Http\Controllers\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkBuilding\StoreLinkBuildingOrderRequest;
use App\Models\LinkBuildingOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LinkBuildingOrderController extends Controller
{
    private const BULK_DISCOUNT_THRESHOLD = 10;
    private const BULK_DISCOUNT_RATE      = 0.10;

    public function index(): JsonResponse
    {
        $user = auth()->user();

        $orders = LinkBuildingOrder::where('user_id', $user->id)
            ->withCount(['items as items_count' => function ($query) {
                $query->selectRaw('sum(quantity)');
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($order) => [
                'id'          => $order->id,
                'order_title' => $order->order_title,
                'total_amount' => $order->total_amount,
                'status'      => $order->status,
                'created_at'  => $order->created_at,
                'items_count' => (int) ($order->items_count ?? 0),
            ]);

        return response()->json(['data' => $orders]);
    }

    public function store(StoreLinkBuildingOrderRequest $request): JsonResponse
    {
        $user = auth()->user();

        $total_links = collect($request->items)->sum('quantity');
        $subtotal    = collect($request->items)->sum(fn ($item) => $item['unit_price'] * $item['quantity']);

        $discount_applied = $total_links >= self::BULK_DISCOUNT_THRESHOLD;
        $total_amount     = $discount_applied
            ? round($subtotal * (1 - self::BULK_DISCOUNT_RATE), 2)
            : round($subtotal, 2);

        $order = DB::transaction(function () use ($request, $user, $total_amount) {
            $order = LinkBuildingOrder::create([
                'user_id'      => $user->id,
                'order_title'  => $request->order_title,
                'order_notes'  => $request->order_notes,
                'total_amount' => $total_amount,
                'status'       => 'pending',
            ]);

            foreach ($request->items as $item_data) {
                $subtotal = round($item_data['unit_price'] * $item_data['quantity'], 2);

                $item = $order->items()->create([
                    'dr_tier_id' => $item_data['dr_tier_id'],
                    'quantity'   => $item_data['quantity'],
                    'unit_price' => $item_data['unit_price'],
                    'subtotal'   => $subtotal,
                ]);

                foreach ($item_data['placements'] as $placement_data) {
                    $item->placements()->create([
                        'row_index'    => $placement_data['row_index'],
                        'keyword'      => $placement_data['keyword'] ?: null,
                        'landing_page' => $placement_data['landing_page'] ?: null,
                        'exact_match'  => $placement_data['exact_match'],
                    ]);
                }
            }

            $order->billing()->create([
                'company'     => $request->billing['company'] ?? null,
                'address'     => $request->billing['address'],
                'city'        => $request->billing['city'],
                'state'       => $request->billing['state'],
                'country'     => $request->billing['country'],
                'postal_code' => $request->billing['postal_code'],
            ]);

            return $order;
        });

        return response()->json([
            'data' => [
                'order_id'     => $order->id,
                'status'       => $order->status,
                'total_amount' => $order->total_amount,
                'created_at'   => $order->created_at,
            ],
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $user = auth()->user();

        $order = LinkBuildingOrder::where('id', $id)
            ->where('user_id', $user->id)
            ->with([
                'items.drTier',
                'items.placements',
                'billing',
            ])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => $order]);
    }
}
