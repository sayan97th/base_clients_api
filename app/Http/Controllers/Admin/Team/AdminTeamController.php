<?php

namespace App\Http\Controllers\Admin\Team;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Team\StoreAdminTeamRequest;
use App\Http\Requests\Admin\Team\UpdateAdminTeamRequest;
use App\Models\AdminTeam;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTeamController extends Controller
{
    /**
     * GET /api/admin/teams/for-select
     *
     * Lightweight list of active teams for dropdown selects.
     * Returns only id, name, and color — no pagination, no members.
     */
    public function forSelect(): JsonResponse
    {
        $teams = AdminTeam::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'max_capacity']);

        return response()->json(['data' => $teams->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $per_page = min((int) $request->query('per_page', 10), 50);
        $search   = $request->query('search');

        $query = AdminTeam::with(['creator:id,first_name,last_name,email'])
            ->withCount('members')
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $teams = $query->paginate($per_page);

        return response()->json([
            'data'         => $teams->map(fn ($t) => $this->formatTeam($t))->values(),
            'current_page' => $teams->currentPage(),
            'last_page'    => $teams->lastPage(),
            'total'        => $teams->total(),
            'per_page'     => $teams->perPage(),
        ]);
    }

    public function store(StoreAdminTeamRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = auth()->user();

        $validated               = $request->validated();
        $validated['created_by'] = $actor->id;

        $team = AdminTeam::create($validated);
        $team->load('creator:id,first_name,last_name,email');
        $team->loadCount('members');

        return response()->json(['data' => $this->formatTeam($team)], 201);
    }

    public function show(string $id): JsonResponse
    {
        $team = AdminTeam::with([
            'creator:id,first_name,last_name,email,profile_photo_path',
            'members:id,first_name,last_name,email,profile_photo_path,job_title',
        ])->find($id);

        if (! $team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        return response()->json(['data' => $this->formatTeamDetail($team)]);
    }

    public function update(UpdateAdminTeamRequest $request, string $id): JsonResponse
    {
        $team = AdminTeam::find($id);

        if (! $team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        $team->update($request->validated());
        $team->load('creator:id,first_name,last_name,email');
        $team->loadCount('members');

        return response()->json(['data' => $this->formatTeam($team->fresh(['creator']))]);
    }

    public function destroy(string $id): JsonResponse
    {
        $team = AdminTeam::find($id);

        if (! $team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully.']);
    }

    public function addMember(Request $request, string $id): JsonResponse
    {
        $team = AdminTeam::find($id);

        if (! $team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role'    => ['nullable', 'string', 'in:lead,member'],
        ]);

        $user_id = (int) $request->input('user_id');
        $role    = $request->input('role', 'member');

        if ($team->members()->where('user_id', $user_id)->exists()) {
            return response()->json(['message' => 'User is already a member of this team.'], 409);
        }

        $team->members()->attach($user_id, [
            'role'      => $role,
            'joined_at' => now(),
        ]);

        $team->load([
            'creator:id,first_name,last_name,email,profile_photo_path',
            'members:id,first_name,last_name,email,profile_photo_path,job_title',
        ]);
        $team->loadCount('members');

        return response()->json(['data' => $this->formatTeamDetail($team)]);
    }

    public function removeMember(string $id, int $user_id): JsonResponse
    {
        $team = AdminTeam::find($id);

        if (! $team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        if (! $team->members()->where('user_id', $user_id)->exists()) {
            return response()->json(['message' => 'User is not a member of this team.'], 404);
        }

        $team->members()->detach($user_id);

        return response()->json(['message' => 'Member removed successfully.']);
    }

    public function updateMemberRole(Request $request, string $id, int $user_id): JsonResponse
    {
        $team = AdminTeam::find($id);

        if (! $team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        $request->validate([
            'role' => ['required', 'string', 'in:lead,member'],
        ]);

        if (! $team->members()->where('user_id', $user_id)->exists()) {
            return response()->json(['message' => 'User is not a member of this team.'], 404);
        }

        $team->members()->updateExistingPivot($user_id, ['role' => $request->input('role')]);

        $team->load([
            'creator:id,first_name,last_name,email,profile_photo_path',
            'members:id,first_name,last_name,email,profile_photo_path,job_title',
        ]);
        $team->loadCount('members');

        return response()->json(['data' => $this->formatTeamDetail($team)]);
    }

    private function formatTeam(AdminTeam $team): array
    {
        return [
            'id'            => $team->id,
            'name'          => $team->name,
            'description'   => $team->description,
            'color'         => $team->color,
            'is_active'     => $team->is_active,
            'created_by'    => $team->created_by,
            'members_count' => $team->members_count ?? 0,
            'creator'       => $team->creator ? [
                'id'         => $team->creator->id,
                'first_name' => $team->creator->first_name,
                'last_name'  => $team->creator->last_name,
                'email'      => $team->creator->email,
            ] : null,
            'created_at'    => $team->created_at,
            'updated_at'    => $team->updated_at,
        ];
    }

    private function formatTeamDetail(AdminTeam $team): array
    {
        $data            = $this->formatTeam($team);
        $data['members'] = $team->members->map(fn ($member) => [
            'id'                => $member->id,
            'first_name'        => $member->first_name,
            'last_name'         => $member->last_name,
            'email'             => $member->email,
            'job_title'         => $member->job_title,
            'profile_photo_url' => $member->profile_photo_url ?? null,
            'role'              => $member->pivot->role,
            'joined_at'         => $member->pivot->joined_at,
        ])->values()->toArray();

        return $data;
    }
}
