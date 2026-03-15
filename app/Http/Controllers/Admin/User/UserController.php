<?php

namespace App\Http\Controllers\Admin\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserWithRolesResource;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /api/admin/users?page=N
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::with(['roles:id,name,display_name', 'organization'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => UserWithRolesResource::collection($users->items()),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'total' => $users->total(),
        ]);
    }

    /**
     * GET /api/admin/users/{user_id}
     */
    public function show(int $user_id): JsonResponse
    {
        $user = User::with(['roles:id,name,display_name', 'organization'])
            ->findOrFail($user_id);

        return response()->json(new UserWithRolesResource($user));
    }

    /**
     * GET /api/admin/users/{user_id}/orders?page=N
     */
    public function orders(int $user_id): JsonResponse
    {
        User::findOrFail($user_id);

        $orders = LinkBuildingOrder::withCount('items')
            ->where('user_id', $user_id)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        $data = $orders->map(fn (LinkBuildingOrder $order) => [
            'id'           => $order->id,
            'order_title'  => $order->order_title,
            'total_amount' => $order->total_amount,
            'status'       => $order->status,
            'created_at'   => $order->created_at,
            'items_count'  => $order->items_count,
        ])->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $orders->currentPage(),
            'last_page'    => $orders->lastPage(),
            'total'        => $orders->total(),
        ]);
    }
}