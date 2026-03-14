<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserWithRolesResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffUserController extends Controller
{
    /**
     * GET /api/staff/users?page=N
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::with(['roles:id,name,display_name', 'organization'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'data'         => UserWithRolesResource::collection($users->items()),
            'current_page' => $users->currentPage(),
            'last_page'    => $users->lastPage(),
            'total'        => $users->total(),
        ]);
    }
}
