<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Mail\OrderReportMail;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderItem;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\OrderReport;
use App\Models\OrderReportRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class OrderReportController extends Controller
{
    /**
     * GET /api/admin/orders/{order}/report
     *
     * Fetch the full report for a given order. Tables are virtual (derived from
     * order items + dr_tiers). Rows are auto-created for every placement that
     * does not yet have an order_report_rows record.
     */
    public function show(string $order): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::with([
            'items.drTier',
            'items.placements.reportRow',
        ])->find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report = OrderReport::firstOrCreate(['order_id' => $link_building_order->id]);

        // Auto-create a report row for every placement that doesn't have one yet
        foreach ($link_building_order->items as $item) {
            foreach ($item->placements as $placement) {
                if (! $placement->reportRow) {
                    $row = OrderReportRow::create(['order_placement_id' => $placement->id]);
                    $placement->setRelation('reportRow', $row);
                }
            }
        }

        return response()->json(
            $this->buildReportResponse($report, $link_building_order)
        );
    }

    /**
     * PATCH /api/admin/orders/{order}/report/rows/{row}
     *
     * Update the delivery details (status, live_link, live_link_date, dr) of a
     * single report row. Read-only fields come from the linked placement.
     */
    public function updateRow(Request $request, string $order, string $row): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::with(['items.placements'])->find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $report_row = OrderReportRow::with([
            'placement.orderItem.drTier',
        ])->find($row);

        if (! $report_row) {
            return response()->json(['message' => 'Row not found.'], 404);
        }

        // Ownership check: row → placement → order_item → order
        if ($report_row->placement->orderItem->order_id !== $link_building_order->id) {
            return response()->json(['message' => 'This row does not belong to this order.'], 403);
        }

        $validated = $request->validate([
            'status'         => ['sometimes', 'in:pending,live,rejected'],
            'live_link'      => ['sometimes', 'nullable', 'url', 'max:2048'],
            'live_link_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'dr'             => ['sometimes', 'nullable', 'integer', 'between:0,100'],
        ]);

        $report_row->update($validated);

        $placement = $report_row->placement;
        $link_type = $placement->orderItem->drTier?->dr_label ?? '';

        return response()->json(
            $this->buildRowResponse($report_row, $placement, $link_type, $link_building_order)
        );
    }

    /**
     * POST /api/admin/orders/{order}/report/send
     *
     * Send the report email to the client and update sent_at.
     * Can be called multiple times (resend).
     */
    public function send(Request $request, string $order): JsonResponse
    {
        $link_building_order = LinkBuildingOrder::with([
            'user',
            'items.drTier',
            'items.placements.reportRow',
        ])->find($order);

        if (! $link_building_order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $report = OrderReport::firstOrCreate(['order_id' => $link_building_order->id]);

        // Ensure all rows exist before sending
        foreach ($link_building_order->items as $item) {
            foreach ($item->placements as $placement) {
                if (! $placement->reportRow) {
                    $row = OrderReportRow::create(['order_placement_id' => $placement->id]);
                    $placement->setRelation('reportRow', $row);
                }
            }
        }

        $report_data    = $this->buildReportResponse($report, $link_building_order);
        $custom_message = $validated['message'] ?? null;
        $client         = $link_building_order->user;

        try {
            Mail::to($client->email)->send(
                new OrderReportMail($report_data, $link_building_order, $custom_message)
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

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Assemble the full report payload used by both show() and send().
     */
    private function buildReportResponse(OrderReport $report, LinkBuildingOrder $order): array
    {
        $global_row_number = 1;

        $tables = $order->items->map(
            function (LinkBuildingOrderItem $item) use (&$global_row_number, $order) {
                $link_type = $item->drTier?->dr_label ?? '';

                $rows = $item->placements->map(
                    function (LinkBuildingOrderPlacement $placement) use ($link_type, &$global_row_number, $order) {
                        $order_number = 'BL-' . str_pad($global_row_number, 5, '0', STR_PAD_LEFT);
                        $global_row_number++;

                        return $this->buildRowResponse(
                            $placement->reportRow,
                            $placement,
                            $link_type,
                            $order,
                            $order_number
                        );
                    }
                )->values()->all();

                return [
                    'id'          => $item->id,
                    'title'       => $link_type,
                    'description' => null,
                    'rows'        => $rows,
                    'created_at'  => $item->created_at,
                    'updated_at'  => $item->updated_at,
                ];
            }
        )->values()->all();

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
     * Build the JSON shape for a single row, combining placement (read-only)
     * and report_row (editable) data.
     *
     * When called from buildReportResponse(), $order_number is already computed.
     * When called from updateRow(), we derive it from the placement's position in the order.
     */
    private function buildRowResponse(
        OrderReportRow $report_row,
        LinkBuildingOrderPlacement $placement,
        string $link_type,
        LinkBuildingOrder $order,
        ?string $order_number = null
    ): array {
        if ($order_number === null) {
            $order_number = $this->deriveOrderNumber($order, $placement->id);
        }

        return [
            'id'             => $report_row->id,
            'placement_id'   => $placement->id,
            'order_number'   => $order_number,
            'link_type'      => $link_type,
            'keyword'        => $placement->keyword,
            'landing_page'   => $placement->landing_page,
            'exact_match'    => $placement->exact_match,
            'request_date'   => $placement->created_at->format('Y-m-d'),
            'status'         => $report_row->status,
            'live_link'      => $report_row->live_link,
            'live_link_date' => $report_row->live_link_date?->format('Y-m-d'),
            'dr'             => $report_row->dr,
            'created_at'     => $report_row->created_at,
            'updated_at'     => $report_row->updated_at,
        ];
    }

    /**
     * Compute the human-readable order number for a placement by finding its
     * global sequential position across all items of the order.
     * The order must have items.placements already loaded.
     */
    private function deriveOrderNumber(LinkBuildingOrder $order, string $placement_id): string
    {
        $counter = 1;

        foreach ($order->items as $item) {
            foreach ($item->placements as $placement) {
                if ($placement->id === $placement_id) {
                    return 'BL-' . str_pad($counter, 5, '0', STR_PAD_LEFT);
                }
                $counter++;
            }
        }

        return 'BL-00000';
    }
}
