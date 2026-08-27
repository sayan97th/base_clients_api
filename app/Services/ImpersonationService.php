<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Shared token-issuance and eligibility logic behind every impersonation entry
 * point in the admin portal. Centralizing this keeps the security-sensitive parts,
 * who is allowed to become who, and how the session is audited, identical no
 * matter which screen triggered the impersonation.
 */
class ImpersonationService
{
    public const STAFF_ROLES = ['super_admin', 'admin', 'staff'];

    /**
     * True when the target account is a client account holding no staff-side role.
     * Used to enforce "client accounts only" for entry points that must never be
     * used to impersonate another admin, regardless of the caller's own role.
     */
    public function isClientOnly(User $target): bool
    {
        $role_names = $target->roles->pluck('name');

        return $role_names->contains('client')
            && $role_names->intersect(self::STAFF_ROLES)->isEmpty();
    }

    /**
     * Issues a short-lived JWT for $target and records an auditable cache entry
     * linking the session back to the admin who started it and where it was
     * started from.
     *
     * @return array{impersonation_token: string, token_type: string, expires_in: int, impersonated_user: array, admin_user: array}
     */
    public function issue(User $admin, User $target, string $origin): array
    {
        /** @var string $token */
        $token = auth()->login($target);

        Cache::put(
            'impersonation:' . $admin->id . ':' . $target->id,
            [
                'admin_id'   => $admin->id,
                'target_id'  => $target->id,
                'origin'     => $origin,
                'started_at' => now()->toISOString(),
            ],
            now()->addHours(8)
        );

        return [
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
        ];
    }

    public function formatUser(User $user): array
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
