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
    private const STAFF_ROLES = ['super_admin', 'admin', 'staff'];

    /**
     * GET /api/admin/users?page=N&type=staff|client
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');

        if ($type !== null && !\in_array($type, ['staff', 'client'], true)) {
            return response()->json([
                'message' => 'The type field must be staff or client.',
                'errors'  => [
                    'type' => ['The selected type is invalid.'],
                ],
            ], 422);
        }

        $query = User::with(['roles:id,name,display_name', 'organization'])->latest();

        if ($type === 'staff') {
            $query->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES));
        } elseif ($type === 'client') {
            $query->whereHas('roles', fn ($q) => $q->where('name', 'client'))
                  ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES));
        }

        $users = $query->paginate(15);

        return response()->json([
            'data'         => UserWithRolesResource::collection($users->items()),
            'current_page' => $users->currentPage(),
            'last_page'    => $users->lastPage(),
            'total'        => $users->total(),
            'per_page'     => $users->perPage(),
            'from'         => $users->firstItem(),
            'to'           => $users->lastItem(),
        ]);
    }

    /**
     * GET /api/admin/users/{user_id}
     */
    public function show(int $user_id): JsonResponse
    {
        $user = User::with(['roles:id,name,display_name', 'organization'])->find($user_id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json(new UserWithRolesResource($user));
    }

    /**
     * GET /api/admin/users/{user_id}/orders?page=N
     */
    public function orders(int $user_id): JsonResponse
    {
        $user = User::find($user_id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $orders = LinkBuildingOrder::withCount('items')
            ->where('user_id', $user_id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

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
            'per_page'     => $orders->perPage(),
            'from'         => $orders->firstItem(),
            'to'           => $orders->lastItem(),
        ]);
    }
}