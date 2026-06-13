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
    private const STAFF_ROLES         = ['super_admin', 'admin', 'staff'];
    private const ALLOWED_SORT_FIELDS = ['first_name', 'email', 'organization', 'created_at'];

    /**
     * GET /api/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $type           = $request->query('type');
        $search         = $request->query('search');
        $role           = $request->query('role');
        $sort_field     = $request->query('sort_field', 'created_at');
        $sort_direction = $request->query('sort_direction', 'desc');
        $date_from      = $request->query('date_from');
        $date_to        = $request->query('date_to');
        $email_status   = $request->query('email_status');
        $account_status = $request->query('account_status');

        if ($type !== null && !\in_array($type, ['staff', 'client'], true)) {
            return response()->json([
                'message' => 'The type field must be staff or client.',
                'errors'  => ['type' => ['The selected type is invalid.']],
            ], 422);
        }

        if ($sort_field !== null && !\in_array($sort_field, self::ALLOWED_SORT_FIELDS, true)) {
            return response()->json([
                'message' => 'The sort_field value is invalid.',
                'errors'  => ['sort_field' => ['The selected sort field is invalid.']],
            ], 422);
        }

        if (!\in_array($sort_direction, ['asc', 'desc'], true)) {
            $sort_direction = 'asc';
        }

        if ($role !== null && !\in_array($role, self::STAFF_ROLES, true)) {
            $role = null;
        }

        $query = User::with(['roles:id,name,display_name', 'organization'])
            ->select('users.*');

        if ($sort_field === 'organization') {
            $query->leftJoin('organizations', 'users.organization_id', '=', 'organizations.id')
                  ->orderBy('organizations.name', $sort_direction);
        } elseif ($sort_field === 'first_name') {
            $query->orderBy('users.first_name', $sort_direction)
                  ->orderBy('users.last_name', $sort_direction);
        } else {
            $query->orderBy('users.' . $sort_field, $sort_direction);
        }

        if ($type === 'staff') {
            $query->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES));
        } elseif ($type === 'client') {
            $query->whereHas('roles', fn ($q) => $q->where('name', 'client'))
                  ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES));
        }

        if ($role !== null) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('users.first_name', 'LIKE', "%{$search}%")
                  ->orWhere('users.last_name', 'LIKE', "%{$search}%")
                  ->orWhereRaw("CONCAT(users.first_name, ' ', users.last_name) LIKE ?", ["%{$search}%"])
                  ->orWhere('users.email', 'LIKE', "%{$search}%")
                  ->orWhereHas('organization', fn ($q) => $q->where('name', 'LIKE', "%{$search}%"));
            });
        }

        if ($date_from !== null) {
            $query->where('users.created_at', '>=', $date_from . ' 00:00:00');
        }

        if ($date_to !== null) {
            $query->where('users.created_at', '<=', $date_to . ' 23:59:59');
        }

        if ($email_status === 'verified') {
            $query->whereNotNull('users.email_verified_at');
        } elseif ($email_status === 'unverified') {
            $query->whereNull('users.email_verified_at');
        }

        if ($account_status === 'active') {
            $query->where('users.is_active', true);
        } elseif ($account_status === 'disabled') {
            $query->where('users.is_active', false);
        }

        $users = $query->paginate(15);

        return response()->json([
            'data'         => UserWithRolesResource::collection($users->items()),
            'current_page' => $users->currentPage(),
            'last_page'    => $users->lastPage(),
            'total'        => $users->total(),
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
            return response()->json(['message' => 'This account is already disabled.'], 409);
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
            'message' => 'User account has been disabled.',
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
            return response()->json(['message' => 'This account is already active.'], 409);
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
            'message' => 'User account has been re-enabled.',
            'user'    => new UserWithRolesResource($user->fresh(['roles:id,name,display_name', 'organization'])),
        ]);
    }

    /**
     * PATCH /api/admin/users/{user_id}
     */
    public function update(Request $request, int $user_id): JsonResponse
    {
        $request->validate([
            'company'            => ['nullable', 'string', 'max:255'],
            'google_studio_link' => ['nullable', 'string', 'max:500'],
            'referrer_id'        => ['nullable', 'string', 'max:255'],
            'note'               => ['nullable', 'string', 'max:10000'],
        ]);

        $user = User::with(['roles:id,name,display_name', 'organization'])->find($user_id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $updatable = ['company', 'google_studio_link', 'referrer_id', 'note'];
        $data      = [];

        foreach ($updatable as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if (!empty($data)) {
            $user->update($data);
        }

        return response()->json([
            'message' => 'User updated successfully.',
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
        ]);
    }
}