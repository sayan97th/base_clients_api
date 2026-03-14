<?php

namespace App\Http\Controllers\Client\Team;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\UpdateTeamMemberRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class TeamMemberController extends Controller
{
    public function index(Team $team): JsonResponse
    {
        $user = auth()->user();

        if (!$user->hasRole('super_admin') && $user->organization_id !== $team->organization_id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        if (!$user->hasTeamPermission($team, 'team_members.view')) {
            return response()->json([
                'message' => 'Forbidden. Missing required team permission.',
            ], 403);
        }

        $members = $team->members()
            ->select('users.id', 'first_name', 'last_name', 'email', 'profile_photo_path')
            ->get()
            ->map(function ($member) {
                return [
                    'id'                  => $member->id,
                    'first_name'          => $member->first_name,
                    'last_name'           => $member->last_name,
                    'full_name'           => $member->full_name,
                    'email'               => $member->email,
                    'profile_photo_path'  => $member->profile_photo_path,
                    'profile_photo_url'   => $member->profile_photo_url,
                    'role'                => $member->pivot->role,
                    'permissions'         => is_string($member->pivot->permissions)
                        ? json_decode($member->pivot->permissions, true)
                        : $member->pivot->permissions,
                    'joined_at' => $member->pivot->joined_at,
                ];
            });

        return response()->json([
            'members' => $members,
        ]);
    }

    public function update(UpdateTeamMemberRequest $request, Team $team, User $user): JsonResponse
    {
        $auth_user = auth()->user();

        if (!$user->hasRole('super_admin') && $auth_user->organization_id !== $team->organization_id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        if (!$team->hasMember($user)) {
            return response()->json([
                'message' => 'User is not a member of this team.',
            ], 404);
        }

        $current_role = $team->getMemberRole($user);

        if ($current_role === 'owner') {
            return response()->json([
                'message' => 'Cannot modify the team owner. Transfer ownership first.',
            ], 403);
        }

        $validated_data = $request->validated();
        $update_data    = [];

        if (isset($validated_data['role'])) {
            $update_data['role'] = $validated_data['role'];
        }

        if (isset($validated_data['permissions'])) {
            $update_data['permissions'] = json_encode($validated_data['permissions']);
        }

        if (!empty($update_data)) {
            $team->members()->updateExistingPivot($user->id, $update_data);
        }

        $updated_member = $team->members()->where('user_id', $user->id)->first();

        return response()->json([
            'message' => 'Team member updated successfully.',
            'member'  => [
                'id'          => $updated_member->id,
                'first_name'  => $updated_member->first_name,
                'last_name'   => $updated_member->last_name,
                'email'       => $updated_member->email,
                'role'        => $updated_member->pivot->role,
                'permissions' => is_string($updated_member->pivot->permissions)
                    ? json_decode($updated_member->pivot->permissions, true)
                    : $updated_member->pivot->permissions,
            ],
        ]);
    }

    public function destroy(Team $team, User $user): JsonResponse
    {
        $auth_user = auth()->user();

        if (!$auth_user->hasRole('super_admin') && $auth_user->organization_id !== $team->organization_id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        if (!$auth_user->hasTeamPermission($team, 'team_members.manage')) {
            return response()->json([
                'message' => 'Forbidden. Missing required team permission.',
            ], 403);
        }

        if (!$team->hasMember($user)) {
            return response()->json([
                'message' => 'User is not a member of this team.',
            ], 404);
        }

        if ($team->getMemberRole($user) === 'owner') {
            return response()->json([
                'message' => 'Cannot remove the team owner.',
            ], 403);
        }

        $team->members()->detach($user->id);

        return response()->json([
            'message' => 'Team member removed successfully.',
        ]);
    }

    public function leave(Team $team): JsonResponse
    {
        $user = auth()->user();

        if (!$team->hasMember($user)) {
            return response()->json([
                'message' => 'You are not a member of this team.',
            ], 404);
        }

        if ($team->getMemberRole($user) === 'owner') {
            $owner_count = $team->members()
                ->wherePivot('role', 'owner')
                ->count();

            if ($owner_count <= 1) {
                return response()->json([
                    'message' => 'Cannot leave the team as the sole owner. Transfer ownership first.',
                ], 403);
            }
        }

        $team->members()->detach($user->id);

        return response()->json([
            'message' => 'You have left the team.',
        ]);
    }
}
