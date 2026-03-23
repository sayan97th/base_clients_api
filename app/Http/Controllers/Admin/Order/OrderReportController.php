<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderReportResource;
use App\Http\Resources\OrderReportTableResource;
use App\Http\Resources\OrderReportRowResource;
use App\Mail\OrderReportMail;
use App\Models\LinkBuildingOrder;
use App\Models\OrderReport;
use App\Models\OrderReportRow;
use App\Models\OrderReportTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class OrderReportController extends Controller
{
    /**
     * GET /api/admin/orders/{order}/report
     * Fetch or auto-create the report for the given order.
     */
    public function show(string $order): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report = OrderReport::firstOrCreate(
            ['order_id' => $link_building_order->id]
        );

        $report->load(['tables.rows']);

        return response()->json(new OrderReportResource($report));
    }

    /**
     * POST /api/admin/orders/{order}/report/tables
     * Create a new table inside the order report.
     */
    public function createTable(Request $request, string $order): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = OrderReport::firstOrCreate(
            ['order_id' => $link_building_order->id]
        );

        $table = $report->tables()->create($validated);
        $table->load('rows');

        return response()->json(new OrderReportTableResource($table), 201);
    }

    /**
     * PATCH /api/admin/orders/{order}/report/tables/{table}
     * Update an existing report table.
     */
    public function updateTable(Request $request, string $order, string $table): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report_table = OrderReportTable::find($table);

        if (! $report_table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $report = OrderReport::where('order_id', $link_building_order->id)->first();

        if (! $report || $report_table->order_report_id !== $report->id) {
            return response()->json(['message' => 'This table does not belong to this order\'s report.'], 403);
        }

        $validated = $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $report_table->update($validated);
        $report_table->load('rows');

        return response()->json(new OrderReportTableResource($report_table));
    }

    /**
     * DELETE /api/admin/orders/{order}/report/tables/{table}
     * Delete a report table and all its rows.
     */
    public function deleteTable(string $order, string $table): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report_table = OrderReportTable::find($table);

        if (! $report_table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $report = OrderReport::where('order_id', $link_building_order->id)->first();

        if (! $report || $report_table->order_report_id !== $report->id) {
            return response()->json(['message' => 'This table does not belong to this order\'s report.'], 403);
        }

        $report_table->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/admin/orders/{order}/report/tables/{table}/rows
     * Add a new row to a report table.
     */
    public function createRow(Request $request, string $order, string $table): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report_table = OrderReportTable::find($table);

        if (! $report_table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $report = OrderReport::where('order_id', $link_building_order->id)->first();

        if (! $report || $report_table->order_report_id !== $report->id) {
            return response()->json(['message' => 'This table does not belong to this order\'s report.'], 403);
        }

        $validated = $request->validate([
            'order_number'   => ['required', 'string', 'max:50'],
            'link_type'      => ['required', 'string', 'max:100'],
            'keyword'        => ['required', 'string', 'max:255'],
            'landing_page'   => ['required', 'url', 'max:2048'],
            'exact_match'    => ['required', 'boolean'],
            'request_date'   => ['required', 'date_format:Y-m-d'],
            'status'         => ['required', 'in:pending,live,rejected'],
            'live_link'      => ['nullable', 'url', 'max:2048'],
            'live_link_date' => ['nullable', 'date_format:Y-m-d'],
            'dr'             => ['nullable', 'integer', 'between:0,100'],
        ]);

        $row = $report_table->rows()->create($validated);

        return response()->json(new OrderReportRowResource($row), 201);
    }

    /**
     * PATCH /api/admin/orders/{order}/report/tables/{table}/rows/{row}
     * Update an existing row.
     */
    public function updateRow(Request $request, string $order, string $table, string $row): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report_table = OrderReportTable::find($table);

        if (! $report_table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $report = OrderReport::where('order_id', $link_building_order->id)->first();

        if (! $report || $report_table->order_report_id !== $report->id) {
            return response()->json(['message' => 'This table does not belong to this order\'s report.'], 403);
        }

        $report_row = OrderReportRow::find($row);

        if (! $report_row) {
            return response()->json(['message' => 'Row not found.'], 404);
        }

        if ($report_row->order_report_table_id !== $report_table->id) {
            return response()->json(['message' => 'This row does not belong to this table.'], 403);
        }

        $validated = $request->validate([
            'order_number'   => ['sometimes', 'string', 'max:50'],
            'link_type'      => ['sometimes', 'string', 'max:100'],
            'keyword'        => ['sometimes', 'string', 'max:255'],
            'landing_page'   => ['sometimes', 'url', 'max:2048'],
            'exact_match'    => ['sometimes', 'boolean'],
            'request_date'   => ['sometimes', 'date_format:Y-m-d'],
            'status'         => ['sometimes', 'in:pending,live,rejected'],
            'live_link'      => ['sometimes', 'nullable', 'url', 'max:2048'],
            'live_link_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'dr'             => ['sometimes', 'nullable', 'integer', 'between:0,100'],
        ]);

        $report_row->update($validated);

        return response()->json(new OrderReportRowResource($report_row));
    }

    /**
     * DELETE /api/admin/orders/{order}/report/tables/{table}/rows/{row}
     * Delete a single row from a report table.
     */
    public function deleteRow(string $order, string $table, string $row): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report_table = OrderReportTable::find($table);

        if (! $report_table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $report = OrderReport::where('order_id', $link_building_order->id)->first();

        if (! $report || $report_table->order_report_id !== $report->id) {
            return response()->json(['message' => 'This table does not belong to this order\'s report.'], 403);
        }

        $report_row = OrderReportRow::find($row);

        if (! $report_row) {
            return response()->json(['message' => 'Row not found.'], 404);
        }

        if ($report_row->order_report_table_id !== $report_table->id) {
            return response()->json(['message' => 'This row does not belong to this table.'], 403);
        }

        $report_row->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/admin/orders/{order}/report/send
     * Send the report by email to the client and update sent_at.
     */
    public function send(Request $request, string $order): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::with('user')->find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report = OrderReport::where('order_id', $link_building_order->id)
            ->with(['tables.rows'])
            ->first();

        if (! $report) {
            return response()->json(['message' => 'Report not found for this order.'], 404);
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $custom_message = $validated['message'] ?? null;
        $client         = $link_building_order->user;

        try {
            Mail::to($client->email)->send(
                new OrderReportMail($report, $link_building_order, $custom_message)
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send the report email: ' . $e->getMessage(),
            ], 500);
        }

        $report->update(['sent_at' => now()]);

        return response()->json([
            'message' => 'Report sent successfully.',
            'sent_at' => $report->sent_at,
        ]);
    }
}
