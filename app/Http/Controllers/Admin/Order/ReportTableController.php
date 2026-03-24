<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Http\Traits\BuildsReportResponse;
use App\Models\LinkBuildingOrder;
use App\Models\OrderReport;
use App\Models\OrderReportTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportTableController extends Controller
{
    use BuildsReportResponse;

    /**
     * POST /api/admin/orders/{order}/report/tables
     *
     * Creates a new table inside the order report.
     * Auto-creates the report if it does not exist yet.
     */
    public function store(Request $request, LinkBuildingOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = OrderReport::firstOrCreate(['order_id' => $order->id]);

        $table = OrderReportTable::create([
            'report_id'   => $report->id,
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
        ]);

        $table->load('rows');

        return response()->json($this->buildTableResponse($table), 201);
    }

    /**
     * PATCH /api/admin/orders/{order}/report/tables/{table}
     *
     * Updates the title and/or description of an existing report table.
     * Validates that the table belongs to the given order.
     */
    public function update(Request $request, LinkBuildingOrder $order, OrderReportTable $table): JsonResponse
    {
        abort_if($table->report->order_id !== $order->id, 404);

        $validated = $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $table->update($validated);
        $table->load('rows');

        return response()->json($this->buildTableResponse($table));
    }

    /**
     * DELETE /api/admin/orders/{order}/report/tables/{table}
     *
     * Permanently deletes a report table and all its rows (cascade).
     * Validates that the table belongs to the given order.
     */
    public function destroy(LinkBuildingOrder $order, OrderReportTable $table): \Illuminate\Http\Response
    {
        abort_if($table->report->order_id !== $order->id, 404);

        $table->delete();

        return response()->noContent();
    }
}
