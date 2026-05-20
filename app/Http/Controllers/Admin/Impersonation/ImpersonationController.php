<?php

namespace App\Http\Controllers\Admin\Impersonation;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ImpersonationController extends Controller
{
    public function impersonate(int $user_id): JsonResponse
    {
        /** @var \App\Models\User $admin */
        $admin = auth()->user();

        $client = User::find($user_id);

        if (! $client) {
            return response()->json(['message' => 'Client not found.'], 404);
        }

        if (! $client->hasRole('client')) {
            return response()->json([
                'message' => 'Only client accounts can be impersonated.',
            ], 403);
        }

        if (! $client->is_active) {
            return response()->json([
                'message' => 'This account is currently disabled and cannot be impersonated.',
            ], 403);
        }

        $client->load(['roles:id,name,display_name', 'organization']);

        /** @var string $token */
        $token = auth()->login($client);

        Cache::put(
            'impersonation:' . $admin->id . ':' . $client->id,
            [
                'admin_id'   => $admin->id,
                'client_id'  => $client->id,
                'started_at' => now()->toISOString(),
            ],
            now()->addHours(8)
        );

        return response()->json([
            'impersonation_token' => $token,
            'token_type'          => 'bearer',
            'expires_in'          => auth()->factory()->getTTL() * 60,
            'impersonated_user'   => $this->formatUser($client),
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
