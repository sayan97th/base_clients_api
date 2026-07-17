<?php

namespace App\Http\Controllers\Admin\Impersonation;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ImpersonationController extends Controller
{
    private const STAFF_ROLES = ['super_admin', 'admin', 'staff'];

    public function impersonate(int $user_id): JsonResponse
    {
        /** @var \App\Models\User $admin */
        $admin = auth()->user();

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

        $target_roles = $target->roles->pluck('name');
        $is_client    = $target_roles->contains('client')
            && $target_roles->intersect(self::STAFF_ROLES)->isEmpty();

        // Client accounts may be impersonated by super_admin and admin (route-gated).
        // Admin-side (team) accounts may only be impersonated by super_admin.
        if (! $is_client) {
            if (! $admin->hasRole('super_admin')) {
                return response()->json([
                    'message' => 'Only super admins can impersonate admin-side users.',
                ], 403);
            }

            if ($target_roles->contains('super_admin')) {
                return response()->json([
                    'message' => 'Super admin accounts cannot be impersonated.',
                ], 403);
            }
        }

        /** @var string $token */
        $token = auth()->login($target);

        Cache::put(
            'impersonation:' . $admin->id . ':' . $target->id,
            [
                'admin_id'   => $admin->id,
                'target_id'  => $target->id,
                'started_at' => now()->toISOString(),
            ],
            now()->addHours(8)
        );

        return response()->json([
            'impersonation_token' => $token,
            'token_type'          => 'bearer',
            'expires_in'          => auth()->factory()->getTTL() * 60,
            'impersonated_user'   => $this->formatUser($target),
            'admin_user'          => [
                'id'         => $admin->id,
                'first_name' => $admin->first_name,
                'last_name'  => $admin->last_name,
                'email'      => $admin->email,
            ],
        ]);
    }

    public function stop(): JsonResponse
    {
        return response()->json([
            'message' => 'Impersonation session ended successfully.',
        ]);
    }

    protected function formatUser(User $user): array
    {
        return [
            'id'                => $user->id,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'email'             => $user->email,
            'profile_photo_url' => $user->profile_photo_url,
            'organization_id'   => $user->organization_id,
            'is_active'         => $user->is_active,
            'roles'             => $user->roles->map(fn ($role) => [
                'id'           => $role->id,
                'name'         => $role->name,
                'display_name' => $role->display_name,
            ])->values(),
            'organization' => $user->organization,
        ];
    }
}
