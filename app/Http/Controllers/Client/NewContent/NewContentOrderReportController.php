<?php

namespace App\Http\Controllers\Client\NewContent;

use App\Http\Controllers\Controller;
use App\Http\Traits\BuildsReportResponse;
use App\Models\NewContentOrder;
use App\Models\OrderReport;
use Illuminate\Http\JsonResponse;

class NewContentOrderReportController extends Controller
{
    use BuildsReportResponse;

    public function show(string $order_id): JsonResponse
    {
        $user = auth()->user();

        $order = NewContentOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->user_id !== $user->id) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $report = OrderReport::with([
                'tables' => fn ($q) => $q->orderBy('created_at', 'asc'),
                'tables.rows' => fn ($q) => $q->orderBy('created_at', 'asc'),
            ])
            ->where('order_id', $order_id)
            ->first();

        if (! $report) {
            return response()->json(['message' => 'Report not found.'], 404);
        }

        return response()->json($this->buildReportResponse($report));
    }
}
