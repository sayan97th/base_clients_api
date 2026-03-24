<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Http\Traits\BuildsReportResponse;
use App\Models\OrderReport;
use Illuminate\Http\JsonResponse;

class OrderReportController extends Controller
{
    use BuildsReportResponse;

    /**
     * GET /api/link-building/orders/{order_id}/report
     *
     * Returns the full report for a given order belonging to the authenticated client.
     * Read-only endpoint — no creation, modification, or deletion allowed.
     */
    public function show(string $order_id): JsonResponse
    {
        $user = auth()->user();

        $report = OrderReport::with([
                'order',
                'tables' => fn ($q) => $q->orderBy('created_at', 'asc'),
                'tables.rows' => fn ($q) => $q->orderBy('created_at', 'asc'),
            ])
            ->where('order_id', $order_id)
            ->first();

        if (! $report) {
            return response()->json(['message' => 'Report not found.'], 404);
        }

        if ($report->order->user_id !== $user->id) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return response()->json($this->buildReportResponse($report));
    }
}
