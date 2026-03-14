<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Requests\SendInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Mail\StaffInvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    /**
     * GET /api/invitations/{token}/validate  (public)
     */
    public function validateToken(string $token): JsonResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if (!$invitation || $invitation->isAccepted() || $invitation->isExpired()) {
            return response()->json([
                'valid'   => false,
                'message' => 'Invitation is invalid or has expired.',
            ], 422);
        }

        return response()->json([
            'valid'      => true,
            'invitation' => new InvitationResource($invitation->load('inviter')),
        ]);
    }

    /**
     * POST /api/invitations/accept  (public)
     */
    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        $invitation = Invitation::where('token', $request->invitation_token)->first();

        if (!$invitation || $invitation->isAccepted() || $invitation->isExpired()) {
            return response()->json([
                'message' => 'Invitation is invalid or has expired.',
            ], 422);
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name'  => $request->last_name,
            'email'      => $invitation->email,
            'password'   => $request->password,
        ]);

        $user->preference()->create();
        $user->billingAddress()->create();
        $user->assignRole($invitation->role);

        $invitation->update(['accepted_at' => now()]);

        /** @var string $token */
        $token = auth()->login($user);

        $user->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => auth()->factory()->getTTL() * 60,
            'user'         => $this->formatUser($user),
        ]);
    }

    /**
     * GET /api/staff/invitations
     */
    public function index(): JsonResponse
    {
        $invitations = Invitation::with('inviter')->latest()->get();

        return response()->json(InvitationResource::collection($invitations));
    }

    /**
     * POST /api/staff/invitations
     */
    public function store(SendInvitationRequest $request): JsonResponse
    {
        /** @var \App\Models\User $sender */
        $sender = auth()->user();

        // Admins can only invite staff, not other admins
        if ($sender->hasRole('admin') && !$sender->hasRole('super_admin') && $request->role === 'admin') {
            return response()->json([
                'message' => 'Admins can only invite staff members.',
            ], 403);
        }

        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'message' => 'A user with this email address already exists.',
                'errors'  => ['email' => ['A user with this email address already exists.']],
            ], 422);
        }

        if (Invitation::where('email', $request->email)->whereNull('accepted_at')->where('expires_at', '>', now())->exists()) {
            return response()->json([
                'message' => 'A pending invitation for this email address already exists.',
                'errors'  => ['email' => ['A pending invitation for this email address already exists.']],
            ], 422);
        }

        $invitation = Invitation::create([
            'email'      => $request->email,
            'role'       => $request->role,
            'token'      => Str::random(64),
            'invited_by' => $sender->id,
            'expires_at' => now()->addDays(7),
        ]);

        $invitation->load('inviter');

        Mail::to($invitation->email)->send(new StaffInvitationMail($invitation));

        return response()->json(new InvitationResource($invitation), 201);
    }

    /**
     * DELETE /api/staff/invitations/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $invitation = Invitation::findOrFail($id);

        if ($invitation->isAccepted()) {
            return response()->json([
                'message' => 'Cannot revoke an invitation that has already been accepted.',
            ], 422);
        }

        $invitation->delete();

        return response()->json(null, 204);
    }

    private function formatUser(\App\Models\User $user): array
    {
        return [
            'id'                => $user->id,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'email'             => $user->email,
            'business_email'    => $user->business_email,
            'phone'             => $user->phone,
            'job_title'         => $user->job_title,
            'profile_photo_url' => $user->profile_photo_url,
            'organization_id'   => $user->organization_id,
            'email_verified_at' => $user->email_verified_at,
            'created_at'        => $user->created_at,
            'updated_at'        => $user->updated_at,
            'roles'             => $user->roles->map(fn ($role) => [
                'id'           => $role->id,
                'name'         => $role->name,
                'display_name' => $role->display_name,
            ])->values(),
            'organization' => $user->organization,
        ];
    }
}
