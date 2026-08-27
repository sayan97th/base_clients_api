<?php

namespace App\Http\Controllers\Admin\Impersonation;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;

class ImpersonationController extends Controller
{
    public function __construct(
        protected ImpersonationService $impersonation_service
    ) {}

    public function impersonate(int $user_id): JsonResponse
    {
        /** @var \App\Models\User $admin */
        $admin = auth()->user();

        // Belt-and-suspenders: the route is already role-gated to super_admin/admin,
        // but impersonation is dangerous enough to also require an explicit
        // permission grant, so an "admin" role alone is not automatically enough.
        if (! $admin->hasPermission('users.impersonate')) {
            return response()->json([
                'message' => 'You have insufficient permissions to use the impersonation feature.',
            ], 403);
        }

        $target = User::with(['roles:id,name,display_name', 'organization'])->find($user_id);

        if (! $target) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($target->id === $admin->id) {
            return response()->json([
                'message' => 'You cannot impersonate your own account.',
            ], 422);
        }

        if (! $target->is_active) {
            return response()->json([
                'message' => 'This account is currently disabled and cannot be impersonated.',
            ], 403);
        }

        $is_client = $this->impersonation_service->isClientOnly($target);

        // Client accounts may be impersonated by super_admin and admin (route-gated).
        // Admin-side (team) accounts may only be impersonated by super_admin.
        if (! $is_client) {
            if (! $admin->hasRole('super_admin')) {
                return response()->json([
                    'message' => 'Only super admins can impersonate admin-side users.',
                ], 403);
            }

            if ($target->roles->pluck('name')->contains('super_admin')) {
                return response()->json([
                    'message' => 'Super admin accounts cannot be impersonated.',
                ], 403);
            }
        }

        return response()->json(
            $this->impersonation_service->issue($admin, $target, origin: 'admin_panel')
        );
    }

    public function stop(): JsonResponse
    {
        return response()->json([
            'message' => 'Impersonation session ended successfully.',
        ]);
    }
}
