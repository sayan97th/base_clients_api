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

        $this->processTeamInvitations($user, $validated_data['invitation_token'] ?? null);

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
        $user = auth()->user();
        $user->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'user' => $user,
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
        $token = auth()->refresh();

        return $this->respondWithToken($token, auth()->user());
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

    protected function respondWithToken(string $token, $user = null): JsonResponse
    {
        $user?->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => $user ? [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'profile_photo_path' => $user->profile_photo_path,
                'profile_photo_url' => $user->profile_photo_url,
                'roles' => $user->roles->pluck('name'),
                'organization' => $user->organization,
            ] : null,
        ]);
    }
}
