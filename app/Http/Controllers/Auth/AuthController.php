<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated_data = $request->validated();

        $user = User::create([
            'first_name' => $validated_data['first_name'],
            'last_name' => $validated_data['last_name'],
            'email' => $validated_data['email'],
            'business_email' => $validated_data['business_email'] ?? null,
            'password' => $validated_data['password'],
        ]);

        $user->preference()->create();
        $user->billingAddress()->create();

        // Always assign the client role on public registration.
        // Admin/staff accounts are only created via the invitation flow.
        $user->assignRole('client');

        $this->processTeamInvitations($user, $validated_data['invitation_token'] ?? null);

        /** @var string $token */
        $token = auth()->login($user);

        return $this->respondWithToken($token, $user);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $token = auth()->attempt($credentials);

        if (!$token) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        return $this->respondWithToken($token, auth()->user());
    }

    public function me(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $user->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'user' => $this->formatUser($user),
            'permissions' => $user->getAllPermissions(),
        ]);
    }

    public function logout(): JsonResponse
    {
        auth()->logout();

        return response()->json([
            'message' => 'Successfully logged out.',
        ]);
    }

    public function refresh(): JsonResponse
    {
        /** @var string $token */
        $token = auth()->refresh();

        /** @var \App\Models\User $user */
        $user = auth()->user();

        return $this->respondWithToken($token, $user);
    }

    protected function processTeamInvitations(User $user, ?string $invitation_token = null): void
    {
        $query = TeamInvitation::where('email', $user->email)
            ->where('status', 'pending')
            ->where('expires_at', '>', now());

        if ($invitation_token) {
            $query->orWhere(function ($q) use ($invitation_token) {
                $q->where('token', $invitation_token)
                    ->where('status', 'pending')
                    ->where('expires_at', '>', now());
            });
        }

        $pending_invitations = $query->get();

        foreach ($pending_invitations as $invitation) {
            $invitation->team->members()->attach($user->id, [
                'role' => $invitation->role,
                'permissions' => is_array($invitation->permissions)
                    ? json_encode($invitation->permissions)
                    : $invitation->permissions,
                'joined_at' => now(),
            ]);

            $invitation->update([
                'status' => 'accepted',
                'user_id' => $user->id,
                'accepted_at' => now(),
            ]);

            if (!$user->organization_id) {
                $user->update([
                    'organization_id' => $invitation->team->organization_id,
                ]);
                $user->refresh();
            }
        }
    }

    protected function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'business_email' => $user->business_email,
            'phone' => $user->phone,
            'job_title' => $user->job_title,
            'profile_photo_url' => $user->profile_photo_url,
            'organization_id' => $user->organization_id,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ])->values(),
            'organization' => $user->organization,
        ];
    }

    protected function respondWithToken(string $token, $user = null): JsonResponse
    {
        $user?->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => $user ? $this->formatUser($user) : null,
        ]);
    }
}
