<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DrTier\StoreDrTierRequest;
use App\Http\Requests\Admin\DrTier\UpdateDrTierRequest;
use App\Models\DrTier;
use App\Models\LinkBuildingOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminLinkBuildingTierController extends Controller
{
    /**
     * GET /api/admin/dr-tiers
     */
    public function index(): JsonResponse
    {
        $tiers = DrTier::withCount('orderItems as orders_count')
            ->withSum('orderItems as revenue_total', 'subtotal')
            ->orderBy('price_per_link')
            ->get();

        return response()->json($tiers->map(fn(DrTier $tier) => $this->formatTier($tier)));
    }

    /**
     * GET /api/admin/dr-tiers/{id}
     */
    public function show(string $id): JsonResponse
    {
        $tier = DrTier::withCount('orderItems as orders_count')
            ->withSum('orderItems as revenue_total', 'subtotal')
            ->find($id);

        if (!$tier) {
            return response()->json(['message' => 'DR Tier not found.'], 404);
        }

        $purchases = LinkBuildingOrderItem::with(['order.user'])
            ->where('dr_tier_id', $tier->id)
            ->latest('created_at')
            ->get()
            ->map(function (LinkBuildingOrderItem $item) {
                return [
                    'order_id'     => $item->order_id,
                    'user'         => [
                        'id'         => $item->order->user->id,
                        'first_name' => $item->order->user->first_name,
                        'last_name'  => $item->order->user->last_name,
                        'email'      => $item->order->user->email,
                    ],
                    'quantity'     => $item->quantity,
                    'subtotal'     => $item->subtotal,
                    'purchased_at' => $item->created_at->toIso8601String(),
                ];
            });

        $unique_buyers = LinkBuildingOrderItem::where('dr_tier_id', $tier->id)
            ->join('link_building_orders', 'link_building_order_items.order_id', '=', 'link_building_orders.id')
            ->distinct('link_building_orders.user_id')
            ->count('link_building_orders.user_id');

        return response()->json([
            'id'              => $tier->id,
            'label'           => $tier->label,
            'traffic_range'   => $tier->traffic_range,
            'word_count'      => $tier->word_count,
            'price_per_link'  => $tier->price_per_link,
            'is_most_popular' => $tier->is_most_popular,
            'is_active'       => $tier->is_active,
            'max_quantity'    => $tier->max_quantity,
            'is_hidden'       => $tier->is_hidden,
            'orders_count'    => $tier->orders_count,
            'revenue_total'   => $tier->revenue_total ?? 0,
            'unique_buyers'   => $unique_buyers,
            'purchases'       => $purchases,
            'created_at'      => $tier->created_at?->toIso8601String(),
            'updated_at'      => $tier->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/admin/dr-tiers
     */
    public function store(StoreDrTierRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tier = DB::transaction(function () use ($data): DrTier {
            if (!empty($data['is_most_popular'])) {
                DrTier::where('is_most_popular', true)->update(['is_most_popular' => false]);
            }

            $data['id']        = (string) Str::uuid();
            $data['is_hidden'] = false;

            return DrTier::create($data);
        });

        $tier->loadCount('orderItems as orders_count')
            ->loadSum('orderItems as revenue_total', 'subtotal');

        return response()->json($this->formatTier($tier), 201);
    }

    /**
     * PATCH /api/admin/dr-tiers/{id}
     */
    public function update(UpdateDrTierRequest $request, string $id): JsonResponse
    {
        $tier = DrTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'DR Tier not found.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($tier, $data): void {
            if (isset($data['is_most_popular']) && $data['is_most_popular']) {
                DrTier::where('is_most_popular', true)
                    ->where('id', '!=', $tier->id)
                    ->update(['is_most_popular' => false]);
            }

            $tier->update($data);
        });

        $tier->refresh()
            ->loadCount('orderItems as orders_count')
            ->loadSum('orderItems as revenue_total', 'subtotal');

        return response()->json($this->formatTier($tier));
    }

    /**
     * DELETE /api/admin/dr-tiers/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $tier = DrTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'DR Tier not found.'], 404);
        }

        $tier->delete();

        return response()->json(null, 204);
    }

    private function formatTier(DrTier $tier): array
    {
        return [
            'id'              => $tier->id,
            'label'           => $tier->label,
            'traffic_range'   => $tier->traffic_range,
            'word_count'      => $tier->word_count,
            'price_per_link'  => $tier->price_per_link,
            'is_most_popular' => $tier->is_most_popular,
            'is_active'       => $tier->is_active,
            'max_quantity'    => $tier->max_quantity,
            'is_hidden'       => $tier->is_hidden,
            'orders_count'    => $tier->orders_count ?? 0,
            'revenue_total'   => $tier->revenue_total ?? 0,
            'created_at'      => $tier->created_at?->toIso8601String(),
            'updated_at'      => $tier->updated_at?->toIso8601String(),
        ];
    }
}
