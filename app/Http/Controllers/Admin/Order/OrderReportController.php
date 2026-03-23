<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Mail\OrderReportMail;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderItem;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\OrderReport;
use App\Models\OrderReportRow;
use App\Models\OrderReportTable;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class OrderReportController extends Controller
{
    /**
     * GET /api/admin/orders/{order_id}/report
     *
     * Returns the full report for a given order including all tables and rows.
     * Auto-creates an empty report record if none exists yet.
     */
    public function show(string $order_id): JsonResponse
    {
        $order = LinkBuildingOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report = OrderReport::with('tables.rows')
            ->firstOrCreate(['order_id' => $order->id]);

        return response()->json($this->buildReportResponse($report));
    }

    /**
     * POST /api/admin/orders/{order_id}/report/tables
     *
     * Adds a new empty table to the order report.
     */
    public function createTable(Request $request, string $order_id): JsonResponse
    {
        $order = LinkBuildingOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report = OrderReport::firstOrCreate(['order_id' => $order->id]);

        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $table = OrderReportTable::create([
            'report_id'   => $report->id,
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
        ]);

        $table->load('rows');

        return response()->json($this->buildTableResponse($table), 201);
    }

    /**
     * PATCH /api/admin/orders/{order_id}/report/tables/{table_id}
     *
     * Updates the title and/or description of an existing report table.
     */
    public function updateTable(Request $request, string $order_id, string $table_id): JsonResponse
    {
        $order = LinkBuildingOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $table = $this->findTableForOrder($order_id, $table_id);

        if (! $table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $validated = $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $table->update($validated);
        $table->load('rows');

        return response()->json($this->buildTableResponse($table));
    }

    /**
     * DELETE /api/admin/orders/{order_id}/report/tables/{table_id}
     *
     * Permanently deletes a report table and all its rows.
     */
    public function deleteTable(string $order_id, string $table_id): JsonResponse
    {
        $order = LinkBuildingOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $table = $this->findTableForOrder($order_id, $table_id);

        if (! $table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $table->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/admin/orders/{order_id}/report/tables/{table_id}/rows
     *
     * Creates a new row inside a specific report table.
     */
    public function createRow(Request $request, string $order_id, string $table_id): JsonResponse
    {
        $order = LinkBuildingOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $table = $this->findTableForOrder($order_id, $table_id);

        if (! $table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $validated = $request->validate([
            'order_number'   => ['required', 'string', 'max:100'],
            'link_type'      => ['required', 'string', 'max:100'],
            'keyword'        => ['required', 'string', 'max:255'],
            'landing_page'   => ['required', 'url', 'max:500'],
            'exact_match'    => ['required', 'boolean'],
            'request_date'   => ['required', 'date_format:Y-m-d'],
            'status'         => ['required', 'in:' . implode(',', OrderReportRow::STATUSES)],
            'live_link'      => ['nullable', 'url', 'max:500'],
            'live_link_date' => ['nullable', 'date_format:Y-m-d'],
            'dr'             => ['nullable', 'integer', 'between:0,100'],
        ]);

        $row = OrderReportRow::create(array_merge($validated, ['table_id' => $table->id]));

        return response()->json($this->buildRowResponse($row), 201);
    }

    /**
     * PATCH /api/admin/orders/{order_id}/report/tables/{table_id}/rows/{row_id}
     *
     * Updates one or more fields of an existing report row.
     */
    public function updateRow(Request $request, string $order_id, string $table_id, string $row_id): JsonResponse
    {
        $order = LinkBuildingOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $table = $this->findTableForOrder($order_id, $table_id);

        if (! $table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $row = OrderReportRow::where('id', $row_id)
            ->where('table_id', $table->id)
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Row not found.'], 404);
        }

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
     * DELETE /api/admin/orders/{order_id}/report/tables/{table_id}/rows/{row_id}
     *
     * Permanently removes a single row from a report table.
     */
    public function deleteRow(string $order_id, string $table_id, string $row_id): JsonResponse
    {
        $order = LinkBuildingOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $table = $this->findTableForOrder($order_id, $table_id);

        if (! $table) {
            return response()->json(['message' => 'Table not found.'], 404);
        }

        $row = OrderReportRow::where('id', $row_id)
            ->where('table_id', $table->id)
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Row not found.'], 404);
        }

        $row->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/admin/orders/{order_id}/report/send
     *
     * Sends the completed order report to the client via email and records sent_at.
     * Can be called multiple times (resend).
     */
    public function send(Request $request, string $order_id): JsonResponse
    {
        $order = LinkBuildingOrder::with('user')->find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $report = OrderReport::with('tables.rows')
            ->firstOrCreate(['order_id' => $order->id]);

        $report_data    = $this->buildReportResponse($report);
        $custom_message = $validated['message'] ?? null;
        $client         = $order->user;

        try {
            Mail::to($client->email)->send(
                new OrderReportMail($report_data, $order, $custom_message)
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send the report email: ' . $e->getMessage(),
            ], 500);
        }

        $report->update(['sent_at' => now()]);

        return response()->json([
            'message' => 'Report sent successfully.',
            'sent_at' => $report->fresh()->sent_at,
        ]);
    }

    /**
     * POST /api/admin/orders/{order_id}/report/import
     *
     * Imports (or re-imports) report rows from the order's original placements,
     * pulling keyword, landing_page and exact_match from the purchase data.
     *
     * - Selection is per-placement (not per-item), so the admin can choose a subset.
     * - A ReportTable is created/reused per order item (grouped by dr_tier).
     * - A ReportRow is created/reused per placement (keyed by order_placement_id).
     * - Rows with status "live" are never modified.
     * - No existing data is ever deleted.
     */
    public function importItems(Request $request, string $order_id): JsonResponse
    {
        $order = LinkBuildingOrder::with(['items.placements', 'items.drTier'])->find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'placement_ids'   => ['required', 'array', 'min:1'],
            'placement_ids.*' => ['string', 'exists:link_building_order_placements,id'],
        ]);

        // Collect all placement IDs that belong to this order
        $valid_placement_ids = $order->items
            ->flatMap(fn (LinkBuildingOrderItem $item) => $item->placements->pluck('id'))
            ->all();

        $invalid_ids = array_diff($validated['placement_ids'], $valid_placement_ids);

        if (! empty($invalid_ids)) {
            return response()->json([
                'message' => 'One or more placement IDs do not belong to this order.',
                'errors'  => [
                    'placement_ids' => array_values(array_map(
                        fn ($id) => "Placement {$id} does not belong to order {$order_id}.",
                        $invalid_ids
                    )),
                ],
            ], 422);
        }

        $report         = OrderReport::firstOrCreate(['order_id' => $order->id]);
        $imported_count = 0;
        $order_number   = 'ORD-' . strtoupper(substr($order->id, 0, 8));

        foreach ($validated['placement_ids'] as $placement_id) {
            /** @var LinkBuildingOrderPlacement $placement */
            $placement = LinkBuildingOrderPlacement::with('orderItem.drTier')->find($placement_id);
            $item      = $placement->orderItem;
            $tier      = $item->drTier;

            $table_title = $tier?->dr_label ?? 'Links';

            // Reuse or create the table for this order item
            $table = OrderReportTable::firstOrCreate(
                ['report_id' => $report->id, 'order_item_id' => $item->id],
                ['title' => $table_title]
            );

            // Create row only if no row exists for this placement (idempotent)
            $exists = OrderReportRow::where('order_placement_id', $placement->id)->exists();

            if (! $exists) {
                OrderReportRow::create([
                    'table_id'           => $table->id,
                    'order_placement_id' => $placement->id,
                    'order_number'       => $order_number,
                    'link_type'          => $table_title,
                    'keyword'            => $placement->keyword ?? '',
                    'landing_page'       => $placement->landing_page ?? '',
                    'exact_match'        => $placement->exact_match,
                    'request_date'       => $order->created_at->toDateString(),
                    'status'             => 'pending',
                    'live_link'          => null,
                    'live_link_date'     => null,
                    'dr'                 => null,
                ]);
                $imported_count++;
            }
            // Row already exists for this placement: skip (idempotent)
        }

        $report->load('tables.rows');

        return response()->json([
            'message'        => 'Successfully imported ' . $imported_count . ' rows.',
            'imported_count' => $imported_count,
            'report'         => $this->buildReportResponse($report),
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Find a report table that belongs to the given order (via the order's report).
     */
    private function findTableForOrder(string $order_id, string $table_id): ?OrderReportTable
    {
        return OrderReportTable::whereHas('report', function ($query) use ($order_id) {
            $query->where('order_id', $order_id);
        })->where('id', $table_id)->first();
    }

    /**
     * Assemble the full report payload (report meta + tables + rows).
     * The report must have the tables.rows relation loaded.
     */
    private function buildReportResponse(OrderReport $report): array
    {
        $tables = ($report->relationLoaded('tables') ? $report->tables : $report->tables()->with('rows')->get())
            ->map(fn (OrderReportTable $table) => $this->buildTableResponse($table))
            ->values()
            ->all();

        return [
            'id'         => $report->id,
            'order_id'   => $report->order_id,
            'sent_at'    => $report->sent_at,
            'tables'     => $tables,
            'created_at' => $report->created_at,
            'updated_at' => $report->updated_at,
        ];
    }

    /**
     * Assemble the JSON shape for a single table (including its rows).
     * The table must have the rows relation loaded.
     */
    private function buildTableResponse(OrderReportTable $table): array
    {
        $rows = ($table->relationLoaded('rows') ? $table->rows : $table->rows()->get())
            ->map(fn (OrderReportRow $row) => $this->buildRowResponse($row))
            ->values()
            ->all();

        return [
            'id'          => $table->id,
            'title'       => $table->title,
            'description' => $table->description,
            'rows'        => $rows,
            'created_at'  => $table->created_at,
            'updated_at'  => $table->updated_at,
        ];
    }

    /**
     * Assemble the JSON shape for a single row.
     */
    private function buildRowResponse(OrderReportRow $row): array
    {
        return [
            'id'             => $row->id,
            'order_number'   => $row->order_number,
            'link_type'      => $row->link_type,
            'keyword'        => $row->keyword,
            'landing_page'   => $row->landing_page,
            'exact_match'    => $row->exact_match,
            'request_date'   => $row->request_date ? Carbon::parse($row->request_date)->format('Y-m-d\TH:i:s.000000\Z') : null,
            'status'         => $row->status,
            'live_link'      => $row->live_link,
            'live_link_date' => $row->live_link_date ? Carbon::parse($row->live_link_date)->format('Y-m-d\TH:i:s.000000\Z') : null,
            'dr'             => $row->dr,
            'created_at'     => $row->created_at,
            'updated_at'     => $row->updated_at,
        ];
    }
}
