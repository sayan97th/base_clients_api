<?php

namespace App\Http\Controllers\Client\PurchaseGroup;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseGroup\StorePurchaseGroupRequest;
use App\Models\PurchaseGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PurchaseGroupController extends Controller
{
    public function store(StorePurchaseGroupRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $group = PurchaseGroup::updateOrCreate(
            ['purchase_group_id' => $request->purchase_group_id],
            [
                'user_id'      => $user->id,
                'order_title'  => $request->order_title,
                'total_amount' => $request->total_amount,
                'created_at'   => $request->created_at,
            ]
        );

        if ($group->wasRecentlyCreated) {
            foreach ($request->orders as $order_data) {
                $group->orders()->create([
                    'order_id'     => $order_data['order_id'],
                    'product_type' => $order_data['product_type'],
                    'total_amount' => $order_data['total_amount'],
                ]);
            }
        }

        $group->load('orders');

        return response()->json(['data' => $this->buildGroupData($group)], 201);
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $groups = PurchaseGroup::where('user_id', $user->id)
            ->with('orders')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $groups->map(fn ($group) => $this->buildGroupData($group))->values(),
        ]);
    }

    public function show(string $purchase_group_id): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $group = PurchaseGroup::where('purchase_group_id', $purchase_group_id)
            ->where('user_id', $user->id)
            ->with('orders')
            ->first();

        if (!$group) {
            return response()->json(['message' => 'Purchase group not found.'], 404);
        }

        return response()->json(['data' => $this->buildGroupData($group)]);
    }

    private function buildGroupData(PurchaseGroup $group): array
    {
        return [
            'purchase_group_id' => $group->purchase_group_id,
            'order_title'       => $group->order_title,
            'total_amount'      => $group->total_amount,
            'created_at'        => $group->created_at,
            'orders'            => $group->orders->map(fn ($order) => [
                'order_id'     => $order->order_id,
                'product_type' => $order->product_type,
                'total_amount' => $order->total_amount,
            ])->values(),
        ];
    }
}
