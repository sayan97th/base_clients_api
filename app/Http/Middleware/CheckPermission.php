<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        $organizationId = $request->route('organization')?->id
            ?? $request->input('organization_id');

        if (!$user->hasPermission($permission, $organizationId)) {
            return response()->json([
                'message' => 'Forbidden. Missing required permission.',
            ], 403);
        }

        return $next($request);
    }
}
