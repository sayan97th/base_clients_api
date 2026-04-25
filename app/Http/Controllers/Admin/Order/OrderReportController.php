<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Http\Traits\BuildsReportResponse;
use App\Mail\OrderReportMail;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderItem;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\OrderReport;
use App\Models\OrderReportRow;
use App\Models\OrderReportTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class OrderReportController extends Controller
{
    use BuildsReportResponse;

    /**
     * GET /api/admin/orders/{order}/report
     *
     * Returns the full report for a given order including all tables and rows.
     * Auto-creates an empty report record if none exists yet.
     */
    public function show(LinkBuildingOrder $order): JsonResponse
    {
        $report = OrderReport::with('tables.rows')
            ->firstOrCreate(['order_id' => $order->id]);

        return response()->json($this->buildReportResponse($report));
    }

    /**
     * POST /api/admin/orders/{order}/report/send
     *
     * Sends the completed order report to the client via email and records sent_at.
     * Can be called multiple times (resend).
     */
    public function send(Request $request, LinkBuildingOrder $order): JsonResponse
    {
        $order->load('user');

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
     * POST /api/admin/orders/{order}/report/import
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
    public function importItems(Request $request, LinkBuildingOrder $order): JsonResponse
    {
        $order->load(['items.placements', 'items.drTier']);

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
                        fn ($id) => "Placement {$id} does not belong to order {$order->id}.",
                        $invalid_ids
                    )),
                ],
            ], 422);
        }

        $report         = OrderReport::firstOrCreate(['order_id' => $order->id]);
        $imported_count = 0;

        foreach ($validated['placement_ids'] as $placement_id) {
            /** @var LinkBuildingOrderPlacement $placement */
            $placement = LinkBuildingOrderPlacement::with('orderItem.drTier')->find($placement_id);
            $item      = $placement->orderItem;
            $tier      = $item->drTier;

            $table_title = $tier?->label ?? 'Links';

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
                    'order_number'       => $order->id,
                    'link_type'          => $table_title,
                    'keyword'            => $placement->keyword ?? '',
                    'landing_page'       => $placement->landing_page ?? '',
                    'exact_match'        => $placement->exact_match,
                    'request_date'       => now()->toDateString(),
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
            'message'        => "{$imported_count} placements imported successfully.",
            'imported_count' => $imported_count,
            'report'         => $this->buildReportResponse($report),
        ]);
    }
}
