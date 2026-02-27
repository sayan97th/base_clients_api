<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        if ($user->hasGlobalRole('super_admin')) {
            return $next($request);
        }

        $organizationId = $request->route('organization')?->id
            ?? $request->input('organization_id');

        if (!$user->hasRole($roles, $organizationId)) {
            return response()->json([
                'message' => 'Forbidden. Insufficient role.',
            ], 403);
        }

        return $next($request);
    }
}
