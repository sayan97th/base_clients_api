<?php

namespace App\Http\Controllers\Admin\BacklinkOrder;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BacklinkOrder\StoreBacklinkOrderRequest;
use App\Http\Requests\Admin\BacklinkOrder\UpdateBacklinkOrderRequest;
use App\Models\BacklinkOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BacklinkOrderController extends Controller
{
    /**
     * DB columns that may appear as a sort_rules key.
     * Computed-only fields (days_left, projected_health) are intentionally excluded.
     */
    private const ALLOWED_SORT_COLUMNS = [
        'order_id', 'team_specific_link_id', 'link_type', 'client', 'keyword',
        'landing_page', 'exact_match', 'notes', 'request_date', 'estimated_delivery_date',
        'estimated_turnaround_days', 'link_builder', 'pen_name', 'partnership',
        'article_title', 'article', 'status', 'live_link', 'live_link_date',
        'dr_lbs', 'posting_fee_lbs', 'current_traffic', 'dr_formula',
        'current_poc', 'current_price', 'lb_tl_approval', 'approval_date', 'final_price',
    ];

    /** All columns that may be targeted by column_filters. */
    private const FILTERABLE_COLUMNS = [
        'order_id', 'team_specific_link_id', 'link_type', 'client', 'keyword',
        'landing_page', 'exact_match', 'notes', 'request_date', 'estimated_delivery_date',
        'estimated_turnaround_days', 'link_builder', 'pen_name', 'partnership',
        'article_title', 'article', 'status', 'live_link', 'live_link_date',
        'dr_lbs', 'posting_fee_lbs', 'current_traffic', 'dr_formula',
        'current_poc', 'current_price', 'lb_tl_approval', 'approval_date', 'final_price',
    ];

    /**
     * POST /api/admin/backlink-orders/search
     *
     * Paginated list with server-side filtering (body-based) and multi-column sorting.
     */
    public function search(Request $request): JsonResponse
    {
        $page           = max(1, (int) $request->input('page', 1));
        $per_page       = min((int) $request->input('per_page', 50), 200);
        $search         = $request->input('search');
        $status         = $request->input('status');
        $link_type      = $request->input('link_type');
        $client         = $request->input('client');
        $link_builder   = $request->input('link_builder');
        $sort_rules     = $request->input('sort_rules', []);
        $column_filters = $request->input('column_filters', []);

        $query = BacklinkOrder::query();

        $this->applyGlobalSearch($query, $search);
        $this->applyQuickFilters($query, $status, $link_type, $client, $link_builder);
        $this->applyColumnFilters($query, $column_filters);
        $this->applySortRules($query, $sort_rules);

        $paginated = $query->paginate($per_page, ['*'], 'page', $page);

        $data = $paginated->getCollection()
            ->map(fn (BacklinkOrder $order) => $this->formatRow($order))
            ->values();

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
     * POST /api/admin/backlink-orders/export
     *
     * Streams a CSV download of all rows matching the same filters as /search.
     * Pagination fields are ignored — all matching rows are exported.
     */
    public function export(Request $request): StreamedResponse
    {
        $search         = $request->input('search');
        $status         = $request->input('status');
        $link_type      = $request->input('link_type');
        $client         = $request->input('client');
        $link_builder   = $request->input('link_builder');
        $sort_rules     = $request->input('sort_rules', []);
        $column_filters = $request->input('column_filters', []);

        $query = BacklinkOrder::query();

        $this->applyGlobalSearch($query, $search);
        $this->applyQuickFilters($query, $status, $link_type, $client, $link_builder);
        $this->applyColumnFilters($query, $column_filters);
        $this->applySortRules($query, $sort_rules);

        $filename = 'backlink-orders-' . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        // Column order matches the table left-to-right as specified.
        $columns = [
            'order_id', 'status', 'team_specific_link_id', 'link_type', 'client', 'keyword',
            'landing_page', 'exact_match', 'notes', 'request_date', 'estimated_delivery_date',
            'estimated_turnaround_days', 'days_left', 'projected_health', 'link_builder',
            'pen_name', 'partnership', 'article_title', 'article', 'live_link', 'live_link_date',
            'dr_lbs', 'posting_fee_lbs', 'current_traffic', 'dr_formula', 'current_poc',
            'current_price', 'lb_tl_approval', 'approval_date', 'final_price',
        ];

        $callback = function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, $columns);

            $query->chunk(500, function ($orders) use ($handle, $columns) {
                foreach ($orders as $order) {
                    $row = $this->formatRow($order);
                    fputcsv($handle, array_map(fn ($col) => $row[$col] ?? '', $columns));
                }
            });

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Applies a global keyword search across key text columns.
     */
    private function applyGlobalSearch($query, ?string $search): void
    {
        if (! filled($search)) {
            return;
        }

        $query->where(function ($q) use ($search) {
            $q->where('order_id', 'like', '%' . $search . '%')
                ->orWhere('client', 'like', '%' . $search . '%')
                ->orWhere('keyword', 'like', '%' . $search . '%')
                ->orWhere('link_builder', 'like', '%' . $search . '%')
                ->orWhere('status', 'like', '%' . $search . '%')
                ->orWhere('partnership', 'like', '%' . $search . '%');
        });
    }

    /**
     * Applies toolbar quick filters (exact/substring matches).
     */
    private function applyQuickFilters($query, ?string $status, ?string $link_type, ?string $client, ?string $link_builder): void
    {
        if (filled($status)) {
            $query->where('status', $status);
        }

        if (filled($link_type)) {
            $query->where('link_type', $link_type);
        }

        if (filled($client)) {
            $query->where('client', 'like', '%' . $client . '%');
        }

        if (filled($link_builder)) {
            $query->where('link_builder', 'like', '%' . $link_builder . '%');
        }
    }

    /**
     * Applies per-column filters (text, select, number, date).
     */
    private function applyColumnFilters($query, array $column_filters): void
    {
        foreach ($column_filters as $filter) {
            $key  = $filter['key']  ?? '';
            $type = $filter['type'] ?? '';

            if (! in_array($key, self::FILTERABLE_COLUMNS, true)) {
                continue;
            }

            match ($type) {
                'text'   => $this->applyTextFilter($query, $key, $filter),
                'select' => $this->applySelectFilter($query, $key, $filter),
                'number' => $this->applyNumberFilter($query, $key, $filter),
                'date'   => $this->applyDateFilter($query, $key, $filter),
                default  => null,
            };
        }
    }

    private function applyTextFilter($query, string $key, array $filter): void
    {
        $value = $filter['value'] ?? '';
        if (filled($value)) {
            $query->where($key, 'like', '%' . $value . '%');
        }
    }

    private function applySelectFilter($query, string $key, array $filter): void
    {
        $values = $filter['values'] ?? [];
        if (! empty($values)) {
            $query->whereIn($key, $values);
        }
    }

    private function applyNumberFilter($query, string $key, array $filter): void
    {
        $min = $filter['min'] ?? '';
        $max = $filter['max'] ?? '';

        if (filled($min)) {
            $query->where($key, '>=', (float) $min);
        }

        if (filled($max)) {
            $query->where($key, '<=', (float) $max);
        }
    }

    private function applyDateFilter($query, string $key, array $filter): void
    {
        $from = $filter['from'] ?? '';
        $to   = $filter['to']   ?? '';

        if (filled($from)) {
            try {
                $from_date = Carbon::createFromFormat('m/d/Y', $from)->startOfDay();
                $query->whereRaw("STR_TO_DATE(`{$key}`, '%m/%d/%Y') >= ?", [$from_date->toDateString()]);
            } catch (\Exception) {
                // Invalid date — skip this bound
            }
        }

        if (filled($to)) {
            try {
                $to_date = Carbon::createFromFormat('m/d/Y', $to)->startOfDay();
                $query->whereRaw("STR_TO_DATE(`{$key}`, '%m/%d/%Y') <= ?", [$to_date->toDateString()]);
            } catch (\Exception) {
                // Invalid date — skip this bound
            }
        }
    }

    /**
     * Applies an ordered list of sort rules. Falls back to order_id asc when empty.
     *
     * When nulls_last is true, MySQL's lack of native NULLS LAST support is worked
     * around with the (col IS NULL OR col = '') trick so empty rows always sink to
     * the bottom regardless of sort direction.
     */
    private function applySortRules($query, array $sort_rules): void
    {
        // Computed-only fields must never reach an ORDER BY clause.
        $unsortable = ['days_left', 'projected_health'];

        $applied = false;

        foreach ($sort_rules as $rule) {
            $key  = $rule['key']       ?? '';
            $dir  = strtolower($rule['direction'] ?? 'asc');
            $nulls_last = (bool) ($rule['nulls_last'] ?? false);

            if (in_array($key, $unsortable, true)) {
                continue;
            }

            if (! in_array($key, self::ALLOWED_SORT_COLUMNS, true)) {
                continue;
            }

            if (! in_array($dir, ['asc', 'desc'], true)) {
                continue;
            }

            if ($nulls_last) {
                // Place NULL and empty-string rows last regardless of direction.
                $query->orderByRaw("(`{$key}` IS NULL OR `{$key}` = ''), `{$key}` {$dir}");
            } else {
                $query->orderBy($key, $dir);
            }

            $applied = true;
        }

        if (! $applied) {
            $query->orderBy('order_id', 'asc');
        }
    }

    /**
     * Formats a BacklinkOrder into the standard API response shape,
     * including the computed fields days_left and projected_health.
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
            'status'                    => $order->status ?? 'New Request',
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
     * days_left        = signed integer days between today and estimated_delivery_date
     * projected_health = (days_left / estimated_turnaround_days) * 100 as "%"
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
}
