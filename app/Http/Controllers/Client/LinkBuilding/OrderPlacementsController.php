<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderPlacementsController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'per_page'  => ['nullable', 'integer', 'min:1'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'search'    => ['nullable', 'string', 'max:255'],
            'status'    => ['nullable', 'string', Rule::in([
                'pending', 'processing', 'completed', 'cancelled', 'new_request', 'payment_pending',
                'New Request', 'Reviewing', 'Ordered', 'Pending', 'Live', 'Quality Control',
                'Cancelled', 'Partnership Check', 'Approved', 'Not Approved', 'Ready', 'Rejected', 'Scheduled',
            ])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d'],
            'dr_type'   => ['nullable', 'string', 'max:50'],
        ]);

        /** @var User $user */
        $user      = auth()->user();
        $per_page  = min((int) $request->get('per_page', 10), 100);
        $search    = $request->get('search');
        $status    = $request->get('status');
        $date_from = $request->get('date_from');
        $date_to   = $request->get('date_to');
        $dr_type   = $request->get('dr_type');

        // ── Client-purchased placements (linked via order → order_item → placement) ──
        $purchased = DB::table('link_building_order_placements as p')
            ->join('link_building_order_items as i', 'i.id', '=', 'p.order_item_id')
            ->join('link_building_orders as o', 'o.id', '=', 'i.order_id')
            ->join('dr_tiers as dt', 'dt.id', '=', 'i.dr_tier_id')
            ->where('o.user_id', $user->id)
            ->where('o.is_hidden', false)
            ->select([
                'p.id',
                'o.id as order_id',
                'o.created_at as start_date',
                DB::raw("COALESCE(NULLIF(TRIM(REPLACE(REPLACE(p.link_type, ' External', ''), ' Internal', '')), ''), dt.label) as dr_type"),
                'p.keyword',
                'p.landing_page',
                // Use the placement-level status when set; fall back to the order status.
                DB::raw('COALESCE(p.status, o.status) as status'),
                'p.live_link',
                'p.completed_date',
                'p.dr',
                'p.request_date',
                DB::raw("'purchased' as source"),
                // Human-readable BL-format ID: use the placement's own order_id when present,
                // otherwise derive from the placement UUID (matches toApiArray() derivedOrderId logic).
                DB::raw("COALESCE(NULLIF(p.order_id, ''), CONCAT('BL-', UPPER(SUBSTR(REPLACE(p.id, '-', ''), 1, 10)))) as display_order_id"),
            ]);

        // ── Admin-assigned standalone placements (linked directly via user_id) ──
        $assigned = DB::table('link_building_order_placements as p')
            ->whereNotNull('p.user_id')
            ->whereNull('p.order_item_id')
            ->where('p.user_id', $user->id)
            ->select([
                'p.id',
                'p.order_id',
                'p.created_at as start_date',
                DB::raw("COALESCE(NULLIF(TRIM(REPLACE(REPLACE(p.link_type, ' External', ''), ' Internal', '')), ''), 'Admin Assigned') as dr_type"),
                'p.keyword',
                'p.landing_page',
                'p.status',
                'p.live_link',
                DB::raw('NULL as completed_date'),
                DB::raw('NULL as dr'),
                'p.request_date',
                DB::raw("'admin_assigned' as source"),
                // Human-readable BL-format ID: use the stored order_id (always BL-XXXXX for admin-created)
                // or derive from placement UUID as fallback (matches toApiArray() derivedOrderId logic).
                DB::raw("COALESCE(p.order_id, CONCAT('BL-', UPPER(SUBSTR(REPLACE(p.id, '-', ''), 1, 10)))) as display_order_id"),
            ]);

        // Union both sets, apply search/status filters, then paginate.
        $query = $purchased->unionAll($assigned);

        // Wrap in a subquery so we can filter on the union result.
        // addBinding(..., 'from') places all inner bindings in the 'from' slot, which
        // getBindings() flushes before 'where'. This preserves the correct PDO positional
        // binding order when outer WHERE conditions (search, whereIn, etc.) are appended.
        $wrapped = DB::table(DB::raw("({$query->toSql()}) as combined"))
            ->addBinding($query->getBindings(), 'from');

        if ($search) {
            $wrapped->where(function ($q) use ($search) {
                $q->where('order_id', 'like', "%{$search}%")
                  ->orWhere('keyword', 'like', "%{$search}%")
                  ->orWhere('landing_page', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $wrapped->where('status', $status);
        }

        if ($date_from) {
            $wrapped->whereRaw("STR_TO_DATE(request_date, '%m/%d/%Y') >= ?", [$date_from]);
        }

        if ($date_to) {
            $wrapped->whereRaw("STR_TO_DATE(request_date, '%m/%d/%Y') <= ?", [$date_to]);
        }

        if ($dr_type) {
            $wrapped->where('dr_type', 'like', '%' . $dr_type . '%');
        }

        $wrapped->orderByRaw('request_date DESC, start_date DESC');

        $paginator = $wrapped->paginate($per_page);

        return response()->json([
            'data'         => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }

    /**
     * POST /api/link-building/order-placements/export
     *
     * Exports order placements as CSV or JSON (for client-side Excel generation).
     * When `row_ids` is provided, only those specific rows are exported (ownership is
     * enforced through the same user-scoped union query). When `format=json`, returns
     * a JSON array instead of streaming a file — the frontend uses this to generate XLSX.
     */
    public function exportPost(Request $request): StreamedResponse|JsonResponse
    {
        $request->validate([
            'search'    => ['nullable', 'string', 'max:255'],
            'status'    => ['nullable', 'string'],
            'row_ids'   => ['nullable', 'array'],
            'row_ids.*' => ['string'],
            'format'    => ['nullable', 'string', 'in:csv,json'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d'],
            'dr_type'   => ['nullable', 'string', 'max:50'],
        ]);

        /** @var User $user */
        $user      = auth()->user();
        $search    = $request->get('search');
        $status    = $request->get('status');
        $row_ids   = (array) $request->get('row_ids', []);
        $format    = $request->get('format', 'csv');
        $date_from = $request->get('date_from');
        $date_to   = $request->get('date_to');
        $dr_type   = $request->get('dr_type');

        $purchased = DB::table('link_building_order_placements as p')
            ->join('link_building_order_items as i', 'i.id', '=', 'p.order_item_id')
            ->join('link_building_orders as o', 'o.id', '=', 'i.order_id')
            ->join('dr_tiers as dt', 'dt.id', '=', 'i.dr_tier_id')
            ->where('o.user_id', $user->id)
            ->where('o.is_hidden', false)
            ->select([
                'p.id',
                DB::raw("COALESCE(NULLIF(p.order_id, ''), CONCAT('BL-', UPPER(SUBSTR(REPLACE(p.id, '-', ''), 1, 10)))) as display_order_id"),
                'o.created_at as start_date',
                DB::raw("COALESCE(NULLIF(TRIM(REPLACE(REPLACE(p.link_type, ' External', ''), ' Internal', '')), ''), dt.label) as dr_type"),
                'p.keyword',
                'p.landing_page',
                DB::raw('COALESCE(p.status, o.status) as status'),
                'p.live_link',
                'p.completed_date',
                'p.dr',
                'p.request_date',
            ]);

        $assigned = DB::table('link_building_order_placements as p')
            ->whereNotNull('p.user_id')
            ->whereNull('p.order_item_id')
            ->where('p.user_id', $user->id)
            ->select([
                'p.id',
                DB::raw("COALESCE(p.order_id, CONCAT('BL-', UPPER(SUBSTR(REPLACE(p.id, '-', ''), 1, 10)))) as display_order_id"),
                'p.created_at as start_date',
                DB::raw("COALESCE(NULLIF(TRIM(REPLACE(REPLACE(p.link_type, ' External', ''), ' Internal', '')), ''), 'Admin Assigned') as dr_type"),
                'p.keyword',
                'p.landing_page',
                'p.status',
                'p.live_link',
                DB::raw('NULL as completed_date'),
                DB::raw('NULL as dr'),
                'p.request_date',
            ]);

        $query = $purchased->unionAll($assigned);

        $wrapped = DB::table(DB::raw("({$query->toSql()}) as combined"))
            ->addBinding($query->getBindings(), 'from');

        if (! empty($row_ids)) {
            $wrapped->whereIn('id', $row_ids);
        } else {
            if ($search) {
                $wrapped->where(function ($q) use ($search) {
                    $q->where('display_order_id', 'like', "%{$search}%")
                      ->orWhere('keyword', 'like', "%{$search}%")
                      ->orWhere('landing_page', 'like', "%{$search}%")
                      ->orWhere('status', 'like', "%{$search}%");
                });
            }
            if ($status) {
                $wrapped->where('status', $status);
            }

            if ($date_from) {
                $wrapped->whereRaw("STR_TO_DATE(request_date, '%m/%d/%Y') >= ?", [$date_from]);
            }

            if ($date_to) {
                $wrapped->whereRaw("STR_TO_DATE(request_date, '%m/%d/%Y') <= ?", [$date_to]);
            }

            if ($dr_type) {
                $wrapped->where('dr_type', 'like', '%' . $dr_type . '%');
            }
        }

        $wrapped->orderByRaw('request_date DESC, start_date DESC');

        if ($format === 'json') {
            $data = $wrapped->get()->map(fn ($row) => (array) $row)->values();
            return response()->json(['data' => $data]);
        }

        $filename = 'order-placements-' . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $columns = ['Order ID', 'Start Date', 'Request Date', 'DR Type', 'Keyword', 'Landing Page', 'Status', 'Live Link', 'Completed Date', 'DR'];

        $callback = function () use ($wrapped, $columns) {
            $handle = fopen('php://output', 'w');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, $columns);

            foreach ($wrapped->cursor() as $row) {
                $row = (array) $row;
                fputcsv($handle, [
                    $row['display_order_id'] ?? '',
                    $row['start_date']        ?? '',
                    $row['request_date']      ?? '',
                    $row['dr_type']           ?? '',
                    $row['keyword']           ?? '',
                    $row['landing_page']      ?? '',
                    $row['status']            ?? '',
                    $row['live_link']         ?? '',
                    $row['completed_date']    ?? '',
                    $row['dr']               ?? '',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * GET /api/link-building/order-placements/export
     *
     * Streams a CSV file containing all of the authenticated client's placements,
     * applying the same search and status filters as the index action.
     */
    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user   = auth()->user();
        $search = $request->get('search');
        $status = $request->get('status');

        $purchased = DB::table('link_building_order_placements as p')
            ->join('link_building_order_items as i', 'i.id', '=', 'p.order_item_id')
            ->join('link_building_orders as o', 'o.id', '=', 'i.order_id')
            ->join('dr_tiers as dt', 'dt.id', '=', 'i.dr_tier_id')
            ->where('o.user_id', $user->id)
            ->where('o.is_hidden', false)
            ->select([
                DB::raw("COALESCE(NULLIF(p.order_id, ''), CONCAT('BL-', UPPER(SUBSTR(REPLACE(p.id, '-', ''), 1, 10)))) as display_order_id"),
                'o.created_at as start_date',
                DB::raw("COALESCE(NULLIF(TRIM(REPLACE(REPLACE(p.link_type, ' External', ''), ' Internal', '')), ''), dt.label) as dr_type"),
                'p.keyword',
                'p.landing_page',
                DB::raw('COALESCE(p.status, o.status) as status'),
                'p.live_link',
                'p.completed_date',
                'p.dr',
            ]);

        $assigned = DB::table('link_building_order_placements as p')
            ->whereNotNull('p.user_id')
            ->whereNull('p.order_item_id')
            ->where('p.user_id', $user->id)
            ->select([
                DB::raw("COALESCE(p.order_id, CONCAT('BL-', UPPER(SUBSTR(REPLACE(p.id, '-', ''), 1, 10)))) as display_order_id"),
                'p.created_at as start_date',
                DB::raw("COALESCE(NULLIF(TRIM(REPLACE(REPLACE(p.link_type, ' External', ''), ' Internal', '')), ''), 'Admin Assigned') as dr_type"),
                'p.keyword',
                'p.landing_page',
                'p.status',
                'p.live_link',
                DB::raw('NULL as completed_date'),
                DB::raw('NULL as dr'),
            ]);

        $query = $purchased->unionAll($assigned)->orderBy('start_date', 'desc');

        $wrapped = DB::table(DB::raw("({$query->toSql()}) as combined"))
            ->addBinding($query->getBindings(), 'from');

        if ($search) {
            $wrapped->where(function ($q) use ($search) {
                $q->where('display_order_id', 'like', "%{$search}%")
                  ->orWhere('keyword', 'like', "%{$search}%")
                  ->orWhere('landing_page', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $wrapped->where('status', $status);
        }

        $filename = 'order-placements-' . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $columns = ['Order ID', 'Start Date', 'DR Type', 'Keyword', 'Landing Page', 'Status', 'Live Link', 'Completed Date', 'DR'];

        $callback = function () use ($wrapped, $columns) {
            $handle = fopen('php://output', 'w');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel compatibility
            fputcsv($handle, $columns);

            foreach ($wrapped->cursor() as $row) {
                $row = (array) $row;
                fputcsv($handle, [
                    $row['display_order_id'] ?? '',
                    $row['start_date']        ?? '',
                    $row['dr_type']           ?? '',
                    $row['keyword']           ?? '',
                    $row['landing_page']      ?? '',
                    $row['status']            ?? '',
                    $row['live_link']         ?? '',
                    $row['completed_date']    ?? '',
                    $row['dr']               ?? '',
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * GET /api/link-building/order-placements/{id}
     *
     * Returns the detail of a single admin-assigned placement belonging to the
     * authenticated client. Only placements directly linked via user_id are
     * accessible here; client-purchased placements are accessed via the order
     * detail endpoint instead.
     */
    public function show(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Placement not found.'], 404);
        }

        /** @var User $user */
        $user = auth()->user();

        $placement = LinkBuildingOrderPlacement::find($id);

        if (! $placement) {
            return response()->json(['message' => 'Placement not found.'], 404);
        }

        if ((int) $placement->user_id !== $user->id) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return response()->json([
            'data' => [
                'id'                      => $placement->id,
                'order_id'                => $placement->order_id ?? null,
                'status'                  => $placement->status ?? 'New Request',
                'link_type'               => $placement->link_type ?? '',
                'keyword'                 => $placement->keyword ?? null,
                'landing_page'            => $placement->landing_page ?? null,
                'exact_match'             => (bool) $placement->exact_match,
                'notes'                   => $placement->notes ?? null,
                'live_link'               => $placement->live_link ?? null,
                'live_link_date'          => $placement->live_link_date ?? null,
                'dr_lbs'                  => $placement->dr_lbs ?? null,
                'request_date'            => $placement->request_date ?? null,
                'estimated_delivery_date' => $placement->estimated_delivery_date ?? null,
                'created_at'              => $placement->created_at?->toIso8601String(),
                'updated_at'              => $placement->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
