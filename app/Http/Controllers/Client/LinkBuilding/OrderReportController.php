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
     * Ownership is enforced at query level — no separate authorization check needed.
     * Rows are ordered by position_index so the placement table renders in the
     * correct order on the Deliverables page.
     */
    public function show(string $order_id): JsonResponse
    {
        $user = auth()->user();

        $report = OrderReport::with([
                'tables' => fn ($q) => $q->orderBy('created_at', 'asc'),
                'tables.rows' => fn ($q) => $q->orderBy('position_index', 'asc')->orderBy('created_at', 'asc'),
            ])
            ->whereHas('order', fn ($q) => $q
                ->where('user_id', $user->id)
                ->where('is_hidden', false)
            )
            ->where('order_id', $order_id)
            ->first();

        if (! $report) {
            return response()->json(['message' => 'Report not found.'], 404);
        }

        return response()->json($this->buildReportResponse($report));
    }
}
