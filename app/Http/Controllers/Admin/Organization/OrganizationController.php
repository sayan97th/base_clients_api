<?php

namespace App\Http\Controllers\Admin\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * GET /api/staff/organizations?page=N
     */
    public function index(Request $request): JsonResponse
    {
        $organizations = Organization::latest()->paginate(15);

        return response()->json([
            'data' => $organizations->items(),
            'current_page' => $organizations->currentPage(),
            'last_page' => $organizations->lastPage(),
            'total' => $organizations->total(),
        ]);
    }
}