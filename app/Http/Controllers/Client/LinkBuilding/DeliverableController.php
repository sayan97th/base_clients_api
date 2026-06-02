<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Http\Traits\BuildsReportResponse;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliverableController extends Controller
{
    use BuildsReportResponse;

    /**
     * GET /api/link-building/deliverables
     *
     * Returns a paginated list of orders for the authenticated client with all
     * report data (tables + rows) embedded in a single response. This avoids
     * the N+1 pattern where the frontend was previously fetching each order's
     * report individually when cards expanded on the Deliverables page.
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

        $query = LinkBuildingOrder::with([
                'report',
                'report.tables'      => fn ($q) => $q->orderBy('created_at', 'asc'),
                'report.tables.rows' => fn ($q) => $q->orderBy('position_index', 'asc')->orderBy('created_at', 'asc'),
            ])
            ->where('user_id', $user->id)
            ->where('is_hidden', false)
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('order_title', 'like', "%{$search}%")
                  ->orWhereRaw('CAST(id AS CHAR) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($per_page);

        $data = collect($paginator->items())->map(function (LinkBuildingOrder $order) {
            $report   = $order->report;
            $all_rows = $report
                ? $report->tables->flatMap(fn ($t) => $t->rows)
                : collect([]);

            return [
                'order_id'       => $order->id,
                'order_title'    => $order->order_title,
                'status'         => $order->status,
                'created_at'     => $order->created_at,
                'total_links'    => $all_rows->count(),
                'live_count'     => $all_rows->where('status', 'live')->count(),
                'pending_count'  => $all_rows->where('status', 'pending')->count(),
                'tables_count'   => $report ? $report->tables->count() : 0,
                'report_sent_at' => $report?->sent_at,
                'report'         => $report ? $this->buildReportResponse($report) : null,
            ];
        });

        return response()->json([
            'data'         => $data,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }
}
