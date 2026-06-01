<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeliverableController extends Controller
{
    /**
     * GET /api/link-building/deliverables
     *
     * Returns a paginated list of orders for the authenticated client,
     * enriched with aggregated link statistics from their associated reports.
     *
     * Each item provides enough data for the Deliverables page card header
     * (title, status, dates, link progress counts). The full placement-level
     * detail is fetched separately via GET /orders/{id}/report when a card
     * is expanded.
     *
     * Query params:
     *   page      int    default 1
     *   per_page  int    default 10, max 100
     *   search    string matches order_title or order UUID prefix
     *   status    string one of: pending, processing, completed, cancelled, payment_pending
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search'   => ['nullable', 'string', 'max:255'],
            'status'   => ['nullable', 'string', Rule::in(LinkBuildingOrder::STATUSES)],
        ]);

        /** @var User $user */
        $user     = auth()->user();
        $per_page = min((int) $request->get('per_page', 10), 100);
        $search   = $request->get('search');
        $status   = $request->get('status');

        // All non-aggregate selected columns are listed in GROUP BY so the query
        // is standards-compliant with MySQL ONLY_FULL_GROUP_BY mode.
        $query = DB::table('link_building_orders as o')
            ->leftJoin('order_reports as r', 'r.order_id', '=', 'o.id')
            ->leftJoin('order_report_tables as t', 't.report_id', '=', 'r.id')
            ->leftJoin('order_report_rows as rr', 'rr.table_id', '=', 't.id')
            ->where('o.user_id', $user->id)
            ->where('o.is_hidden', false)
            ->groupBy('o.id', 'o.order_title', 'o.status', 'o.created_at', 'r.sent_at')
            ->select([
                'o.id as order_id',
                'o.order_title',
                'o.status',
                'o.created_at',
                DB::raw('COUNT(rr.id) as total_links'),
                DB::raw("SUM(CASE WHEN rr.status = 'live'    THEN 1 ELSE 0 END) as live_count"),
                DB::raw("SUM(CASE WHEN rr.status = 'pending' THEN 1 ELSE 0 END) as pending_count"),
                DB::raw('COUNT(DISTINCT t.id) as tables_count'),
                'r.sent_at as report_sent_at',
            ])
            ->orderBy('o.created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('o.order_title', 'like', "%{$search}%")
                  ->orWhereRaw('CAST(o.id AS CHAR) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($status) {
            $query->where('o.status', $status);
        }

        $paginator = $query->paginate($per_page);

        $data = collect($paginator->items())->map(fn ($row) => [
            'order_id'       => $row->order_id,
            'order_title'    => $row->order_title,
            'status'         => $row->status,
            'created_at'     => $row->created_at,
            'total_links'    => (int) ($row->total_links  ?? 0),
            'live_count'     => (int) ($row->live_count   ?? 0),
            'pending_count'  => (int) ($row->pending_count ?? 0),
            'tables_count'   => (int) ($row->tables_count  ?? 0),
            'report_sent_at' => $row->report_sent_at,
        ]);

        return response()->json([
            'data'         => $data,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }
}
