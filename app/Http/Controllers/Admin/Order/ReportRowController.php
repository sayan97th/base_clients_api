<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Http\Traits\BuildsReportResponse;
use App\Models\LinkBuildingOrder;
use App\Models\OrderReportRow;
use App\Models\OrderReportTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportRowController extends Controller
{
    use BuildsReportResponse;

    /**
     * POST /api/admin/orders/{order}/report/tables/{table}/rows
     *
     * Adds a new row to a report table. All fields are optional.
     * Validates that the table belongs to the given order.
     */
    public function store(Request $request, LinkBuildingOrder $order, OrderReportTable $table): JsonResponse
    {
        abort_if($table->report->order_id !== $order->id, 404);

        $validated = $request->validate([
            'order_number'   => ['nullable', 'string', 'max:100'],
            'link_type'      => ['nullable', 'string', 'max:200'],
            'keyword'        => ['nullable', 'string', 'max:500'],
            'landing_page'   => ['nullable', 'url', 'max:2048'],
            'exact_match'    => ['nullable', 'boolean'],
            'request_date'   => ['nullable', 'date_format:Y-m-d'],
            'status'         => ['nullable', 'in:' . implode(',', OrderReportRow::STATUSES)],
            'live_link'      => ['nullable', 'url', 'max:2048'],
            'live_link_date' => ['nullable', 'date_format:Y-m-d'],
            'dr'             => ['nullable', 'integer', 'between:0,100'],
        ]);

        $row = OrderReportRow::create(array_merge($validated, [
            'table_id' => $table->id,
            'status'   => $validated['status'] ?? 'pending',
        ]));

        return response()->json($this->buildRowResponse($row), 201);
    }

    /**
     * PATCH /api/admin/orders/{order}/report/tables/{table}/rows/{row}
     *
     * Partially updates a row. Only fields present in the request are modified.
     * Validates that the table belongs to the order and the row belongs to the table.
     */
    public function update(Request $request, LinkBuildingOrder $order, OrderReportTable $table, OrderReportRow $row): JsonResponse
    {
        abort_if($table->report->order_id !== $order->id, 404);
        abort_if($row->table_id !== $table->id, 404);

        $validated = $request->validate([
            'order_number'   => ['sometimes', 'string', 'max:100'],
            'link_type'      => ['sometimes', 'string', 'max:100'],
            'keyword'        => ['sometimes', 'string', 'max:255'],
            'landing_page'   => ['sometimes', 'url', 'max:500'],
            'exact_match'    => ['sometimes', 'boolean'],
            'request_date'   => ['sometimes', 'date_format:Y-m-d'],
            'status'         => ['sometimes', 'in:' . implode(',', OrderReportRow::STATUSES)],
            'live_link'      => ['sometimes', 'nullable', 'url', 'max:500'],
            'live_link_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'dr'             => ['sometimes', 'nullable', 'integer', 'between:0,100'],
        ]);

        $row->update($validated);

        return response()->json($this->buildRowResponse($row->fresh()));
    }

    /**
     * DELETE /api/admin/orders/{order}/report/tables/{table}/rows/{row}
     *
     * Permanently removes a single row from a report table.
     * Validates that the table belongs to the order and the row belongs to the table.
     */
    public function destroy(LinkBuildingOrder $order, OrderReportTable $table, OrderReportRow $row): \Illuminate\Http\Response
    {
        abort_if($table->report->order_id !== $order->id, 404);
        abort_if($row->table_id !== $table->id, 404);

        $row->delete();

        return response()->noContent();
    }
}
