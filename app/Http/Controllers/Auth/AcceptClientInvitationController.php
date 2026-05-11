<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptClientInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AcceptClientInvitationController extends Controller
{
    /**
     * GET /api/client-invitations/{token}/validate  (public)
     */
    public function validateToken(string $token): JsonResponse
    {
        $invitation = Invitation::where('token', $token)
            ->where('role', 'client')
            ->with('inviter')
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        return response()->json([
            'valid'      => $invitation->isPending(),
            'invitation' => new InvitationResource($invitation),
        ]);
    }

    /**
     * POST /api/client-invitations/accept  (public)
     */
    public function accept(AcceptClientInvitationRequest $request): JsonResponse
    {
        $invitation = Invitation::where('token', $request->invitation_token)
            ->where('role', 'client')
            ->first();

        if (!$invitation || !$invitation->isPending()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => [
                    'invitation_token' => ['This invitation has expired or has already been used.'],
                ],
            ], 422);
        }

        if (User::where('email', $invitation->email)->exists()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => [
                    'invitation_token' => ['An account with this email address already exists.'],
                ],
            ], 422);
        }

        $default_organization = Organization::findDefault();

        $user = User::create([
            'first_name'        => $request->first_name,
            'last_name'         => $request->last_name,
            'email'             => $invitation->email,
            'password'          => $request->password,
            'is_active'         => true,
            'email_verified_at' => now(),
            'organization_id'   => $default_organization?->id,
        ]);

        $user->preference()->create();
        $user->billingAddress()->create();
        $user->assignRole('client');

        $invitation->update(['accepted_at' => now()]);

        /** @var string $token */
        $token = auth()->login($user);

        $user->load(['roles.permissions', 'organization']);

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => auth()->factory()->getTTL() * 60,
            'user'         => [
                'id'              => $user->id,
                'first_name'      => $user->first_name,
                'last_name'       => $user->last_name,
                'email'           => $user->email,
                'organization_id' => $user->organization_id,
                'organization'    => $user->organization,
                'roles'           => $user->roles->map(fn ($role) => [
                    'id'           => $role->id,
                    'name'         => $role->name,
                    'display_name' => $role->display_name,
                ])->values(),
                'permissions'     => $user->getAllPermissions(),
                'created_at'      => $user->created_at,
                'updated_at'      => $user->updated_at,
            ],
        ]);
    }
}
