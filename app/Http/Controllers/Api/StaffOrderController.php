<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffOrderController extends Controller
{
    /**
     * GET /api/staff/orders?page=N
     */
    public function index(Request $request): JsonResponse
    {
        $orders = LinkBuildingOrder::with(['user', 'items', 'billing', 'invoice'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'data'         => $orders->items(),
            'current_page' => $orders->currentPage(),
            'last_page'    => $orders->lastPage(),
            'total'        => $orders->total(),
        ]);
    }
}
