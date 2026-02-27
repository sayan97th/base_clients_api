<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,name,display_name')->get();

        return response()->json(['roles' => $roles]);
    }

    public function assignRole(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'role' => ['required', 'string', 'exists:roles,name'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);

        $user->assignRole(
            $request->input('role'),
            $request->input('organization_id')
        );

        $user->load('roles');

        return response()->json([
            'message' => 'Role assigned successfully.',
            'user' => $user,
        ]);
    }

    public function revokeRole(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'role' => ['required', 'string', 'exists:roles,name'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);

        $user->removeRole(
            $request->input('role'),
            $request->input('organization_id')
        );

        $user->load('roles');

        return response()->json([
            'message' => 'Role revoked successfully.',
            'user' => $user,
        ]);
    }
}
