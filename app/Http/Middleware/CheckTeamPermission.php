<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTeamPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $team = $request->route('team');

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$team instanceof Team) {
            $team = Team::find($team);
        }

        if (!$team) {
            return response()->json([
                'message' => 'Team not found.',
            ], 404);
        }

        if ($user->organization_id !== $team->organization_id) {
            return response()->json([
                'message' => 'Forbidden. You do not belong to this organization.',
            ], 403);
        }

        if (!$user->hasTeamPermission($team, $permission)) {
            return response()->json([
                'message' => 'Forbidden. Missing required team permission.',
            ], 403);
        }

        return $next($request);
    }
}
