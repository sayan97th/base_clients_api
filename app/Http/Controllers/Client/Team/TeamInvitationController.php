<?php

namespace App\Http\Controllers\Client\Team;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\SendTeamInvitationRequest;
use App\Jobs\SendEmailJob;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TeamInvitationController extends Controller
{
    public function index(Team $team): JsonResponse
    {
        $user = auth()->user();

        if (!$user->hasRole('super_admin') && $user->organization_id !== $team->organization_id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        if (!$user->hasTeamPermission($team, 'team_members.manage')) {
            return response()->json([
                'message' => 'Forbidden. Missing required team permission.',
            ], 403);
        }

        $invitations = $team->invitations()
            ->with('invitedBy:id,first_name,last_name,email')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'invitations' => $invitations,
        ]);
    }

    public function store(SendTeamInvitationRequest $request, Team $team): JsonResponse
    {
        $user           = auth()->user();
        $validated_data = $request->validated();
        $email          = $validated_data['email'];

        if ($user->organization_id !== $team->organization_id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $member_by_email = $team->members()
            ->where('email', $email)
            ->first();

        if ($member_by_email) {
            return response()->json([
                'message' => 'This user is already a member of the team.',
            ], 409);
        }

        $pending_invitation = TeamInvitation::where('team_id', $team->id)
            ->where('email', $email)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($pending_invitation) {
            return response()->json([
                'message' => 'A pending invitation already exists for this email.',
            ], 409);
        }

        $existing_user = User::where('email', $email)->first();

        $invitation = TeamInvitation::create([
            'team_id'    => $team->id,
            'invited_by' => $user->id,
            'user_id'    => $existing_user?->id,
            'email'      => $email,
            'role'        => $validated_data['role'] ?? 'member',
            'permissions' => $validated_data['permissions'] ?? null,
            'token'      => Str::random(64),
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        $invitation->load(['team.organization', 'invitedBy']);

        SendEmailJob::dispatchWithThrottle(
            new TeamInvitationMail($invitation, (bool) $existing_user),
            $email,
        );

        return response()->json([
            'message'    => 'Invitation sent successfully.',
            'invitation' => $invitation,
        ], 201);
    }

    public function accept(string $token): JsonResponse
    {
        $user = auth()->user();

        $invitation = TeamInvitation::where('token', $token)->first();

        if (!$invitation) {
            return response()->json([
                'message' => 'Invitation not found.',
            ], 404);
        }

        if (!$invitation->isPending()) {
            return response()->json([
                'message' => 'This invitation is no longer valid.',
            ], 410);
        }

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return response()->json([
                'message' => 'This invitation was sent to a different email address.',
            ], 403);
        }

        if ($invitation->team->hasMember($user)) {
            $invitation->update([
                'status'      => 'accepted',
                'user_id'     => $user->id,
                'accepted_at' => now(),
            ]);

            return response()->json([
                'message' => 'You are already a member of this team.',
                'team'    => $invitation->team,
            ]);
        }

        $invitation->team->members()->attach($user->id, [
            'role'        => $invitation->role,
            'permissions' => is_array($invitation->permissions)
                ? json_encode($invitation->permissions)
                : $invitation->permissions,
            'joined_at' => now(),
        ]);

        $invitation->update([
            'status'      => 'accepted',
            'user_id'     => $user->id,
            'accepted_at' => now(),
        ]);

        if (!$user->organization_id) {
            $user->update([
                'organization_id' => $invitation->team->organization_id,
            ]);
        }

        $invitation->team->load('members:id,first_name,last_name,email');

        return response()->json([
            'message' => 'Invitation accepted. You have joined the team.',
            'team'    => $invitation->team,
        ]);
    }

    public function decline(string $token): JsonResponse
    {
        $user = auth()->user();

        $invitation = TeamInvitation::where('token', $token)->first();

        if (!$invitation) {
            return response()->json([
                'message' => 'Invitation not found.',
            ], 404);
        }

        if (!$invitation->isPending()) {
            return response()->json([
                'message' => 'This invitation is no longer valid.',
            ], 410);
        }

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return response()->json([
                'message' => 'This invitation was sent to a different email address.',
            ], 403);
        }

        $invitation->update([
            'status'  => 'declined',
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Invitation declined.',
        ]);
    }

    public function cancel(Team $team, TeamInvitation $invitation): JsonResponse
    {
        $user = auth()->user();

        if ($invitation->team_id !== $team->id) {
            return response()->json([
                'message' => 'Invitation does not belong to this team.',
            ], 404);
        }

        if (!$user->hasTeamPermission($team, 'team_members.manage')) {
            return response()->json([
                'message' => 'Forbidden. Missing required team permission.',
            ], 403);
        }

        if ($invitation->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending invitations can be cancelled.',
            ], 422);
        }

        $invitation->update([
            'status' => 'cancelled',
        ]);

        return response()->json([
            'message' => 'Invitation cancelled.',
        ]);
    }

    public function resend(Team $team, TeamInvitation $invitation): JsonResponse
    {
        $user = auth()->user();

        if ($invitation->team_id !== $team->id) {
            return response()->json([
                'message' => 'Invitation does not belong to this team.',
            ], 404);
        }

        if (!$user->hasTeamPermission($team, 'team_members.manage')) {
            return response()->json([
                'message' => 'Forbidden. Missing required team permission.',
            ], 403);
        }

        if ($invitation->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending invitations can be resent.',
            ], 422);
        }

        $invitation->update([
            'expires_at' => now()->addDays(7),
        ]);

        $existing_user = User::where('email', $invitation->email)->first();

        $invitation->load(['team.organization', 'invitedBy']);

        SendEmailJob::dispatchWithThrottle(
            new TeamInvitationMail($invitation, (bool) $existing_user),
            $invitation->email,
        );

        return response()->json([
            'message' => 'Invitation resent.',
        ]);
    }

    public function myInvitations(): JsonResponse
    {
        $user = auth()->user();

        $invitations = TeamInvitation::where('email', $user->email)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->with([
                'team:id,name,slug,description',
                'team.organization:id,name',
                'invitedBy:id,first_name,last_name,email',
            ])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'invitations' => $invitations,
        ]);
    }
}
