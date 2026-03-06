<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\StoreTeamRequest;
use App\Http\Requests\Team\UpdateTeamRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        $user = auth()->user();

        if ($user->hasRole('super_admin')) {
            $teams = Team::with('organization:id,name')
                ->withCount('members')
                ->get();
        } else {
            $teams = Team::where('organization_id', $user->organization_id)
                ->withCount('members')
                ->get();
        }

        return response()->json([
            'teams' => $teams,
        ]);
    }

    public function store(StoreTeamRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated_data = $request->validated();

        $team = Team::create([
            'organization_id' => $user->organization_id,
            'created_by' => $user->id,
            'name' => $validated_data['name'],
            'slug' => Str::slug($validated_data['name']),
            'description' => $validated_data['description'] ?? null,
        ]);

        $team->members()->attach($user->id, [
            'role' => 'owner',
            'permissions' => json_encode(Team::TEAM_PERMISSIONS),
            'joined_at' => now(),
        ]);

        $team->load('members:id,first_name,last_name,email');

        return response()->json([
            'message' => 'Team created successfully.',
            'team' => $team,
        ], 201);
    }

    public function show(Team $team): JsonResponse
    {
        $user = auth()->user();

        if (!$user->hasRole('super_admin') && $user->organization_id !== $team->organization_id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $team->load([
            'members:id,first_name,last_name,email',
            'organization:id,name',
        ]);
        $team->loadCount(['members', 'pendingInvitations']);

        return response()->json([
            'team' => $team,
        ]);
    }

    public function update(UpdateTeamRequest $request, Team $team): JsonResponse
    {
        $user = auth()->user();

        if (!$user->hasRole('super_admin') && $user->organization_id !== $team->organization_id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $validated_data = $request->validated();

        if (isset($validated_data['name']) && !isset($validated_data['slug'])) {
            $validated_data['slug'] = Str::slug($validated_data['name']);
        }

        $team->update($validated_data);

        return response()->json([
            'message' => 'Team updated successfully.',
            'team' => $team->fresh(),
        ]);
    }

    public function destroy(Team $team): JsonResponse
    {
        $user = auth()->user();

        if (!$user->hasRole('super_admin') && $user->organization_id !== $team->organization_id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $can_delete = $user->hasPermission('teams.delete')
            || $user->getTeamRole($team) === 'owner';

        if (!$can_delete) {
            return response()->json([
                'message' => 'Forbidden. Insufficient permissions to delete this team.',
            ], 403);
        }

        $team->delete();

        return response()->json([
            'message' => 'Team deleted successfully.',
        ]);
    }
}
