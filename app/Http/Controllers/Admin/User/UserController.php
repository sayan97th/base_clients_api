<?php

namespace App\Http\Controllers\Admin\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserWithRolesResource;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use App\Models\UserBan;
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
     * PATCH /api/admin/users/{user_id}/ban
     */
    public function ban(Request $request, int $user_id): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var \App\Models\User $actor */
        $actor = auth()->user();

        $user = User::with(['roles:id,name,display_name', 'organization'])->find($user_id);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($actor->id === $user->id) {
            return response()->json(['message' => 'You cannot disable your own account.'], 422);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account is already disabled.'], 422);
        }

        $user_roles = $user->roles->pluck('name');

        if ($actor->hasRole('admin')) {
            $is_client_only = $user_roles->contains('client') && $user_roles->intersect(self::STAFF_ROLES)->isEmpty();

            if (! $is_client_only) {
                return response()->json(['message' => 'You do not have permission to disable this account.'], 403);
            }
        }

        $user->update(['is_active' => false]);

        UserBan::create([
            'user_id'   => $user->id,
            'banned_by' => $actor->id,
            'reason'    => $request->input('reason'),
            'action'    => 'ban',
        ]);

        return response()->json([
            'message' => 'Account has been disabled successfully.',
            'user'    => new UserWithRolesResource($user->fresh(['roles:id,name,display_name', 'organization'])),
        ]);
    }

    /**
     * PATCH /api/admin/users/{user_id}/unban
     */
    public function unban(int $user_id): JsonResponse
    {
        /** @var \App\Models\User $actor */
        $actor = auth()->user();

        $user = User::with(['roles:id,name,display_name', 'organization'])->find($user_id);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->is_active) {
            return response()->json(['message' => 'This account is already active.'], 422);
        }

        $user_roles = $user->roles->pluck('name');

        if ($actor->hasRole('admin')) {
            $is_client_only = $user_roles->contains('client') && $user_roles->intersect(self::STAFF_ROLES)->isEmpty();

            if (! $is_client_only) {
                return response()->json(['message' => 'You do not have permission to re-enable this account.'], 403);
            }
        }

        $user->update(['is_active' => true]);

        UserBan::create([
            'user_id'   => $user->id,
            'banned_by' => $actor->id,
            'reason'    => null,
            'action'    => 'unban',
        ]);

        return response()->json([
            'message' => 'Account has been re-enabled successfully.',
            'user'    => new UserWithRolesResource($user->fresh(['roles:id,name,display_name', 'organization'])),
        ]);
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