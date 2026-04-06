<?php

namespace App\Http\Controllers\Admin\BacklinkOrder;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BacklinkOrder\StoreBacklinkOrderRequest;
use App\Http\Requests\Admin\BacklinkOrder\UpdateBacklinkOrderRequest;
use App\Models\BacklinkOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class BacklinkOrderController extends Controller
{
    /** Columns allowed for sorting. */
    private const SORTABLE_FIELDS = [
        'order_id',
        'client',
        'keyword',
        'status',
        'request_date',
        'estimated_delivery_date',
        'link_builder',
        'final_price',
        'current_traffic',
        'dr_lbs',
    ];

    /**
     * GET /api/admin/backlink-orders
     *
     * Returns a paginated, searchable, sortable list of backlink orders.
     */
    public function index(Request $request): JsonResponse
    {
        $search         = $request->input('search');
        $status         = $request->input('status');
        $client         = $request->input('client');
        $link_builder   = $request->input('link_builder');
        $sort_field     = $request->input('sort_field', 'order_id');
        $sort_direction = $request->input('sort_direction', 'asc');
        $per_page       = min((int) $request->input('per_page', 50), 200);

        if (! in_array($sort_field, self::SORTABLE_FIELDS, true)) {
            $sort_field = 'order_id';
        }

        if (! in_array($sort_direction, ['asc', 'desc'], true)) {
            $sort_direction = 'asc';
        }

        $query = BacklinkOrder::query();

        if (filled($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('order_id', 'like', '%' . $search . '%')
                    ->orWhere('client', 'like', '%' . $search . '%')
                    ->orWhere('keyword', 'like', '%' . $search . '%')
                    ->orWhere('link_builder', 'like', '%' . $search . '%')
                    ->orWhere('partnership', 'like', '%' . $search . '%')
                    ->orWhere('status', 'like', '%' . $search . '%');
            });
        }

        if (filled($status)) {
            $query->where('status', $status);
        }

        if (filled($client)) {
            $query->where('client', 'like', '%' . $client . '%');
        }

        if (filled($link_builder)) {
            $query->where('link_builder', 'like', '%' . $link_builder . '%');
        }

        $query->orderBy($sort_field, $sort_direction);

        $paginated = $query->paginate($per_page);

        $data = $paginated->getCollection()->map(fn (BacklinkOrder $order) => $this->formatRow($order))->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
            'total'        => $paginated->total(),
            'from'         => $paginated->firstItem(),
            'to'           => $paginated->lastItem(),
        ]);
    }

    /**
     * POST /api/admin/backlink-orders
     *
     * Creates a new backlink order row.
     */
    public function store(StoreBacklinkOrderRequest $request): JsonResponse
    {
        $order = BacklinkOrder::create($request->validated());

        return response()->json([
            'message' => 'Backlink order created successfully.',
            'data'    => $this->formatRow($order),
        ], 201);
    }

    /**
     * PUT /api/admin/backlink-orders/{id}
     *
     * Fully replaces all fields of an existing backlink order.
     */
    public function update(UpdateBacklinkOrderRequest $request, string $id): JsonResponse
    {
        $order = BacklinkOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Backlink order not found.'], 404);
        }

        $order->update($request->validated());

        return response()->json([
            'message' => 'Backlink order updated successfully.',
            'data'    => $this->formatRow($order->fresh()),
        ]);
    }

    /**
     * PATCH /api/admin/backlink-orders/{id}
     *
     * Partially updates specific fields of a backlink order.
     */
    public function partialUpdate(Request $request, string $id): JsonResponse
    {
        $order = BacklinkOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Backlink order not found.'], 404);
        }

        $validated = $request->validate($this->partialUpdateRules($id));

        $order->update($validated);

        return response()->json([
            'message' => 'Backlink order updated successfully.',
            'data'    => $this->formatRow($order->fresh()),
        ]);
    }

    /**
     * DELETE /api/admin/backlink-orders/{id}
     *
     * Permanently deletes a backlink order row.
     */
    public function destroy(string $id): JsonResponse
    {
        $order = BacklinkOrder::find($id);

        if (! $order) {
            return response()->json(['message' => 'Backlink order not found.'], 404);
        }

        $order->delete();

        return response()->json(['message' => 'Backlink order deleted successfully.']);
    }

    /**
     * GET /api/admin/backlink-orders/export
     *
     * Streams a CSV download of all matching backlink orders.
     */
    public function export(Request $request): Response
    {
        $search       = $request->input('search');
        $status       = $request->input('status');
        $client       = $request->input('client');
        $link_builder = $request->input('link_builder');
        $sort_field   = $request->input('sort_field', 'order_id');
        $sort_dir     = $request->input('sort_direction', 'asc');

        if (! in_array($sort_field, self::SORTABLE_FIELDS, true)) {
            $sort_field = 'order_id';
        }

        if (! in_array($sort_dir, ['asc', 'desc'], true)) {
            $sort_dir = 'asc';
        }

        $query = BacklinkOrder::query();

        if (filled($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('order_id', 'like', '%' . $search . '%')
                    ->orWhere('client', 'like', '%' . $search . '%')
                    ->orWhere('keyword', 'like', '%' . $search . '%')
                    ->orWhere('link_builder', 'like', '%' . $search . '%')
                    ->orWhere('partnership', 'like', '%' . $search . '%')
                    ->orWhere('status', 'like', '%' . $search . '%');
            });
        }

        if (filled($status)) {
            $query->where('status', $status);
        }

        if (filled($client)) {
            $query->where('client', 'like', '%' . $client . '%');
        }

        if (filled($link_builder)) {
            $query->where('link_builder', 'like', '%' . $link_builder . '%');
        }

        $query->orderBy($sort_field, $sort_dir);

        $filename = 'backlink_orders_' . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $columns = [
            'Order ID', 'Team Specific Link ID', 'Link Type', 'Client', 'Keyword',
            'Landing Page', 'Exact Match', 'Notes', 'Request Date', 'Estimated Delivery Date',
            'Estimated Turnaround Days', 'Days Left', 'Projected Health', 'Link Builder',
            'Pen Name', 'Partnership', 'Article Title', 'Article', 'Status', 'Live Link',
            'Live Link Date', 'DR LBS', 'Posting Fee LBS', 'Current Traffic', 'DR Formula',
            'Current POC', 'Current Price', 'LB TL Approval', 'Approval Date', 'Final Price',
        ];

        $callback = function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, $columns);

            $query->chunk(500, function ($orders) use ($handle) {
                foreach ($orders as $order) {
                    $row    = $this->formatRow($order);
                    fputcsv($handle, [
                        $row['order_id'],
                        $row['team_specific_link_id'],
                        $row['link_type'],
                        $row['client'],
                        $row['keyword'],
                        $row['landing_page'],
                        $row['exact_match'],
                        $row['notes'],
                        $row['request_date'],
                        $row['estimated_delivery_date'],
                        $row['estimated_turnaround_days'],
                        $row['days_left'],
                        $row['projected_health'],
                        $row['link_builder'],
                        $row['pen_name'],
                        $row['partnership'],
                        $row['article_title'],
                        $row['article'],
                        $row['status'],
                        $row['live_link'],
                        $row['live_link_date'],
                        $row['dr_lbs'],
                        $row['posting_fee_lbs'],
                        $row['current_traffic'],
                        $row['dr_formula'],
                        $row['current_poc'],
                        $row['current_price'],
                        $row['lb_tl_approval'],
                        $row['approval_date'],
                        $row['final_price'],
                    ]);
                }
            });

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Formats a BacklinkOrder into the API response array,
     * including computed fields days_left and projected_health.
     */
    private function formatRow(BacklinkOrder $order): array
    {
        [$days_left, $projected_health] = $this->computeDeliveryMetrics(
            $order->estimated_delivery_date,
            $order->estimated_turnaround_days,
        );

        return [
            'id'                        => $order->id,
            'order_id'                  => $order->order_id ?? '',
            'team_specific_link_id'     => $order->team_specific_link_id ?? '',
            'link_type'                 => $order->link_type ?? '',
            'client'                    => $order->client ?? '',
            'keyword'                   => $order->keyword ?? '',
            'landing_page'              => $order->landing_page ?? '',
            'exact_match'               => $order->exact_match ?? 'No',
            'notes'                     => $order->notes ?? '',
            'request_date'              => $order->request_date ?? '',
            'estimated_delivery_date'   => $order->estimated_delivery_date ?? '',
            'estimated_turnaround_days' => (string) ($order->estimated_turnaround_days ?? ''),
            'days_left'                 => $days_left,
            'projected_health'          => $projected_health,
            'link_builder'              => $order->link_builder ?? '',
            'pen_name'                  => $order->pen_name ?? '',
            'partnership'               => $order->partnership ?? '',
            'article_title'             => $order->article_title ?? '',
            'article'                   => $order->article ?? '',
            'status'                    => $order->status ?? 'Pending',
            'live_link'                 => $order->live_link ?? '',
            'live_link_date'            => $order->live_link_date ?? '',
            'dr_lbs'                    => $order->dr_lbs ?? '',
            'posting_fee_lbs'           => $order->posting_fee_lbs ?? '',
            'current_traffic'           => $order->current_traffic ?? '',
            'dr_formula'                => $order->dr_formula ?? '',
            'current_poc'               => $order->current_poc ?? '',
            'current_price'             => $order->current_price ?? '',
            'lb_tl_approval'            => $order->lb_tl_approval ?? '',
            'approval_date'             => $order->approval_date ?? '',
            'final_price'               => $order->final_price ?? '',
            'created_at'                => $order->created_at?->toIso8601String(),
            'updated_at'                => $order->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Computes days_left and projected_health from the stored date strings.
     *
     * @return array{string, string}  [$days_left, $projected_health]
     */
    private function computeDeliveryMetrics(?string $estimated_delivery_date, $estimated_turnaround_days): array
    {
        if (empty($estimated_delivery_date)) {
            return ['', ''];
        }

        try {
            $delivery_date = Carbon::createFromFormat('m/d/Y', $estimated_delivery_date)->startOfDay();
        } catch (\Exception) {
            return ['', ''];
        }

        $days_left = (int) Carbon::today()->diffInDays($delivery_date, false);

        $turnaround = (int) ($estimated_turnaround_days ?? 0);

        if ($turnaround <= 0) {
            return [(string) $days_left, ''];
        }

        $projected_health = (int) round(($days_left / $turnaround) * 100);

        return [(string) $days_left, $projected_health . '%'];
    }

    /**
     * Validation rules for PATCH (partial update) — all fields are nullable/sometimes.
     */
    private function partialUpdateRules(string $id): array
    {
        return [
            'order_id'                  => 'sometimes|string|max:50|unique:backlink_orders,order_id,' . $id,
            'team_specific_link_id'     => 'sometimes|nullable|string|max:50',
            'link_type'                 => 'sometimes|nullable|string|in:DA 30+ External,DA 40+ External,DA 50+ External,DA 30+ Internal,DA 40+ Internal',
            'client'                    => 'sometimes|nullable|string|max:255',
            'keyword'                   => 'sometimes|nullable|string|max:500',
            'landing_page'              => 'sometimes|nullable|url|max:2000',
            'exact_match'               => 'sometimes|nullable|in:Yes,No',
            'notes'                     => 'sometimes|nullable|string',
            'request_date'              => 'sometimes|nullable|string|max:20',
            'estimated_delivery_date'   => 'sometimes|nullable|string|max:20',
            'estimated_turnaround_days' => 'sometimes|nullable|integer|min:0',
            'link_builder_user_id'      => 'sometimes|nullable|integer|exists:users,id',
            'link_builder'              => 'sometimes|nullable|string|max:255',
            'pen_name'                  => 'sometimes|nullable|string|max:255',
            'partnership'               => 'sometimes|nullable|string|max:2000',
            'article_title'             => 'sometimes|nullable|string|max:500',
            'article'                   => 'sometimes|nullable|url|max:2000',
            'status'                    => 'sometimes|nullable|in:Live,Pending,In Progress,Cancelled',
            'live_link'                 => 'sometimes|nullable|url|max:2000',
            'live_link_date'            => 'sometimes|nullable|string|max:20',
            'dr_lbs'                    => 'sometimes|nullable|string|max:20',
            'posting_fee_lbs'           => 'sometimes|nullable|string|max:50',
            'current_traffic'           => 'sometimes|nullable|string|max:50',
            'dr_formula'                => 'sometimes|nullable|string|max:50',
            'current_poc'               => 'sometimes|nullable|string|max:255',
            'current_price'             => 'sometimes|nullable|string|max:100',
            'lb_tl_approval'            => 'sometimes|nullable|string|max:255',
            'approval_date'             => 'sometimes|nullable|string|max:20',
            'final_price'               => 'sometimes|nullable|string|max:100',
        ];
    }
}
